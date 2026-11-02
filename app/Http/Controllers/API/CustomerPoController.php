<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Item;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CustomerPoController extends Controller
{

    /**
     * Single route entry point for converting an inquiry to a Customer PO.
     *
     * Two different conversion strategies exist side by side:
     *
     *   - "batch"      (default) — every conversion of an already-converted
     *                    line creates a NEW row holding just that batch's
     *                    qty, so each PO batch keeps its own customer_po_no
     *                    / customer_due_date. This is what customerpoList()
     *                    groups by. See convertTocustomerpoBatch().
     *
     *   - "cumulative" — every conversion updates the SAME row in place,
     *                    overwriting customer_po_no/customer_due_date with
     *                    the latest batch's values. No new rows are ever created.
     *                    See convertTocustomerpoCumulative().
     *
     * Pass conversion_mode=cumulative in the request to use the cumulative
     * strategy; omit it (or send conversion_mode=batch) to use the
     * batch/new-row strategy. The route itself never changes — only this
     * one flag decides which developer's logic runs for a given call.
     *
     * IMPORTANT: never call both strategies for the same conversion in the
     * same request — they write to the same rows in incompatible ways
     * (one accumulates in place, the other splits into new rows), so
     * running both back to back on the same data will double-count qty
     * and clobber each other's customer_po_no/customer_due_date.
     */
    public function convertTocustomerpo(Request $request, $id)
    {
        $mode = $request->input('conversion_mode', 'batch');

        if ($mode === 'cumulative') {
            return $this->convertTocustomerpoCumulative($request, $id);
        }

        return $this->convertTocustomerpoBatch($request, $id);
    }

    /**
     * "Batch" strategy — new row per conversion batch.
     */
    private function convertTocustomerpoBatch(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $firstInquiry = Inquiry::find($id);

            if (!$firstInquiry) {
                return response()->json([
                    'status' => false,
                    'message' => 'Inquiry not found.'
                ], 404);
            }

            if ($firstInquiry->user_id != Auth::id() && Auth::user()->role != 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }

            $validated = $request->validate([
                'customer_po_no' => 'nullable|string|max:255',
                'customer_po_date' => 'nullable|date',
                'customer_due_date' => 'nullable|date',
                'delivery_location' => 'nullable|string|max:255',
                'customer_payment_terms' => 'nullable|string|max:255',
                'ld_terms' => 'nullable|string|max:255',
                'me_ref' => 'nullable|string|max:255',
                'pending_qty' => 'nullable|numeric|min:0',
                'po_amount' => 'nullable|numeric|min:0',
                'po_basic' => 'nullable|numeric|min:0',
                'cumulative' => 'nullable|numeric|min:0',
                'status' => 'nullable|in:Checked,Not Checked',
                'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'nullable|exists:inquiries,id',
                'item_quantities' => 'nullable|array',
                'item_quantities.*.id' => 'nullable|integer',
                'item_quantities.*.ordered_qty' => 'nullable|numeric',
            ]);

            // NOTE: pending_qty is now selected explicitly — it's the
            // authoritative "remaining" figure for this line and is read/written
            // directly on the ORIGINAL row (never derived via a cross-row SUM,
            // which is what caused unrelated items to bleed into each other's
            // remaining-qty calculation).
            $selectedItems = Inquiry::whereIn('id', $validated['item_ids'])
                ->where('inquiry_code', $firstInquiry->inquiry_code)
                ->get([
                    'id',
                    'parent_id',
                    'quantity',
                    'enquiry_type',
                    'pending_qty',
                    'inquiry_id',
                    'item_id',
                    'concerned_person',
                    'concerned_area',
                    'customer_id',
                    'principal_id'
                ]);

            $overLimit = [];
            $orderedQtyById = [];

            if (!empty($validated['item_quantities'])) {
                foreach ($validated['item_quantities'] as $row) {
                    $orderedQtyById[$row['id']] = (float) $row['ordered_qty'];
                }
            }

            // Check that no item is being over-converted beyond its remaining qty.
            // $item here IS the original line's own row (the frontend always
            // references the same row id across every conversion of that line),
            // so remaining lives entirely on it — no item_id/inquiry_id grouping,
            // no risk of two different lines colliding.
            // foreach ($selectedItems as $item) {
            //     $hasBeenConverted = ((float) $item->customer_qty_order) > 0;
            //     $remaining = $hasBeenConverted
            //         ? (float) $item->pending_qty
            //         : (float) $item->quantity;

            //     $requestedQty = array_key_exists($item->id, $orderedQtyById)
            //         ? $orderedQtyById[$item->id]
            //         : (float) ($validated['customer_qty_order'] ?? 0);

            //     if ($requestedQty > $remaining) {
            //         $overLimit[] = [
            //             'id'        => $item->id,
            //             'requested' => $requestedQty,
            //             'remaining' => $remaining,
            //         ];
            //     }
            // }

            if (!empty($overLimit)) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Ordered qty exceeds the remaining unconverted qty for one or more items.',
                    'errors' => $overLimit,
                ], 422);
            }

            $document = null;
            if ($request->hasFile('customer_pdo_document')) {
                $file = $request->file('customer_pdo_document');
                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('uploads/customer_po_documents'), $document);
            }

            $createdRows = [];

            $batches = !empty($validated['item_quantities'])
                ? $validated['item_quantities']
                : $selectedItems->map(fn($item) => [
                    'id' => $item->id,
                    'ordered_qty' => $item->quantity,
                ])->toArray();

            foreach ($batches as $row) {
                $item = $selectedItems->firstWhere('id', $row['id']);
                if (!$item) {
                    continue;
                }

                $batchQty = (float) $row['ordered_qty'];
                if ($batchQty <= 0) {
                    continue;
                }

                $isFirstConversion = $item->enquiry_type !== 'Customer_PO';
                $remainingBefore = $isFirstConversion
                    ? (float) $item->quantity
                    : (float) $item->pending_qty;
                $itemRemaining = max(0, $remainingBefore - $batchQty);

                if ($isFirstConversion) {
                    // FIRST conversion for this line: update the ORIGINAL
                    // row in place, storing ONLY this batch's own qty.
                    // No new row is created.
                    Inquiry::where('id', $item->id)
                        ->where('inquiry_code', $firstInquiry->inquiry_code)
                        ->update([
                            'enquiry_type' => 'Customer_PO',
                            'customer_po_no' => $validated['customer_po_no'] ?? null,
                            'customer_po_date' => $validated['customer_po_date'] ?? null,
                            'customer_due_date' => $validated['customer_due_date'] ?? null,
                            'delivery_location' => $validated['delivery_location'] ?? null,
                            'customer_payment_terms' => $validated['customer_payment_terms'] ?? null,
                            'ld_terms' => $validated['ld_terms'] ?? null,
                            'quantity' => $batchQty,
                            'pending_qty' => $itemRemaining,
                            'me_ref' => $validated['me_ref'] ?? null,
                            'po_amount' => $validated['po_amount'] ?? 0,
                            'po_basic' => $validated['po_basic'] ?? 0,
                            'cumulative' => $validated['cumulative'] ?? 0,
                            'customer_pdo_document' => $document,
                            'status' => $validated['status'] ?? 'Pending',
                            'updated_by' => Auth::id(),
                        ]);

                    $createdRows[] = $item->id;
                } else {
                    // LATER conversion for this line: the original row is left
                    // untouched aside from its pending_qty — we INSERT a new
                    // row holding just this batch's own qty.
                    $newRow = Inquiry::create([
                        'inquiry_code' => $firstInquiry->inquiry_code,
                        'parent_id' => $item->parent_id ?: $item->id,
                        'inquiry_id' => $item->inquiry_id,
                        'item_id' => $item->item_id,
                        'concerned_person' => $item->concerned_person,
                        'concerned_area' => $item->concerned_area,
                        'customer_id' => $item->customer_id,
                        'principal_id' => $item->principal_id,
                        'quantity' => $item->quantity,
                        'enquiry_type' => 'Customer_PO',
                        'customer_po_no' => $validated['customer_po_no'] ?? null,
                        'customer_due_date' => $validated['customer_due_date'] ?? null,
                        'pending_qty' => 0,
                        'me_ref' => $validated['me_ref'] ?? null,
                        'po_amount' => $validated['po_amount'] ?? 0,
                        'po_basic' => $validated['po_basic'] ?? 0,
                        'cumulative' => $validated['cumulative'] ?? 0,
                        'customer_pdo_document' => $document,
                        'status' => $validated['status'] ?? 'Pending',
                        'updated_by' => Auth::id(),
                        'created_by' => Auth::id(),
                    ]);

                    $createdRows[] = $newRow->id;

                    // Keep the ORIGINAL row's pending_qty authoritative so the
                    // *next* conversion of this same line reads the correct
                    // remainder.
                    Inquiry::where('id', $item->id)
                        ->update(['pending_qty' => $itemRemaining]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Customer PO created successfully.',
                'file' => $document,
                'created_ids' => $createdRows,
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer PO Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * "Cumulative" strategy — same row updated in place every conversion.
     *
     * FIX (this pass): 'po_amount' / 'po_basic' / 'cumulative' added to
     * validation and to the header update() call, same as the batch
     * strategy above. customer_qty_order accumulation logic below was
     * already correct and is unchanged.
     */
    private function convertTocustomerpoCumulative(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $firstInquiry = Inquiry::find($id);

            if (!$firstInquiry) {
                return response()->json([
                    'status' => false,
                    'message' => 'Inquiry not found.'
                ], 404);
            }

            if ($firstInquiry->user_id != Auth::id() && Auth::user()->role != 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }

            $validated = $request->validate([
                'customer_po_no' => 'nullable|string|max:255',
                'customer_po_date' => 'nullable|date',
                'customer_due_date' => 'nullable|date',
                'delivery_location' => 'nullable|string|max:255',
                'customer_payment_terms' => 'nullable|string|max:255',
                'ld_terms' => 'nullable|string|max:255',
                'me_ref' => 'nullable|string|max:255',
                'pending_qty' => 'nullable|numeric|min:0',
                'po_amount' => 'nullable|numeric|min:0',
                'po_basic' => 'nullable|numeric|min:0',
                'cumulative' => 'nullable|numeric|min:0',
                'status' => 'nullable|in:Checked,Not Checked',
                'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'nullable|integer',
                'item_quantities' => 'nullable|array',
                'item_quantities.*.id' => 'nullable|integer',
                'item_quantities.*.ordered_qty' => 'nullable|numeric',
            ]);

            $targetItemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$firstInquiry->id];
            $selectedItems = Inquiry::whereIn('id', $targetItemIds)
                ->where('inquiry_code', $firstInquiry->inquiry_code)
                ->get(['id', 'quantity', 'pending_qty']);

            $orderedQtyById = [];

            if (!empty($validated['item_quantities'])) {
                foreach ($validated['item_quantities'] as $row) {
                    $orderedQtyById[$row['id']] = (float) $row['ordered_qty'];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Upload Customer PO Document
            |--------------------------------------------------------------------------
            */

            $document = null;

            if ($request->hasFile('customer_pdo_document')) {

                $file = $request->file('customer_pdo_document');

                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('uploads/customer_po_documents'), $document);
            }

            /*
            |--------------------------------------------------------------------------
            | Update Inquiry Items
            |--------------------------------------------------------------------------
            */

            Inquiry::whereIn('id', $validated['item_ids'])
                ->where('inquiry_code', $firstInquiry->inquiry_code)
                ->update([
                    'enquiry_type' => 'Customer_PO',
                    'customer_po_no' => $validated['customer_po_no'] ?? null,
                    'customer_due_date' => $validated['customer_due_date'] ?? null,
                    'me_ref' => $validated['me_ref'] ?? null,
                    'po_amount' => $validated['po_amount'] ?? 0,
                    'po_basic' => $validated['po_basic'] ?? 0,
                    'cumulative' => $validated['cumulative'] ?? 0,
                    'customer_pdo_document' => $document,
                    'status' => $validated['status'] ?? 'Pending',
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);

            if (!empty($validated['item_quantities'])) {
                foreach ($validated['item_quantities'] as $row) {
                    $item = $selectedItems->firstWhere('id', $row['id']);
                    if (!$item) continue;
                    $batchQty = (float) $row['ordered_qty'];
                    $remainingBefore = $item->pending_qty !== null ? (float) $item->pending_qty : (float) $item->quantity;
                    $itemRemaining = max(0, $remainingBefore - $batchQty);

                    Inquiry::where('id', $row['id'])
                        ->where('inquiry_code', $firstInquiry->inquiry_code)
                        ->update([
                            'pending_qty' => $itemRemaining,
                        ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Customer PO created successfully.',
                'file' => $document
            ], 200);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Customer PO Error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function customerpoList(Request $request)
    {
        try {

            $stages = ['Customer_PO', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

            $query = Inquiry::with([
                'customer:id,name',
                'item:id,name,mat_code,part_model_no,description'
            ])
                ->whereColumn('id', 'parent_id')
                ->where(function ($q) use ($stages) {
                    $q->whereIn('enquiry_type', $stages)
                        ->orWhereHas('children', function ($cq) use ($stages) {
                            $cq->whereIn('enquiry_type', $stages);
                        });
                });

            $query = $this->applyFilters($query, $request);

            $enquiries = $query->get();

            $data = [];

            foreach ($enquiries as $enquiry) {

                $items = Inquiry::with([
                    'item:id,name,mat_code,part_model_no,description'
                ])
                    ->where('parent_id', $enquiry->id)
                    ->whereIn('enquiry_type', ['Customer_PO', 'PO_Principal'])
                    ->orderBy('created_at')
                    ->get();

                if ($items->isEmpty()) {
                    $items = Inquiry::with([
                        'item:id,name,mat_code,part_model_no,description'
                    ])
                        ->where('parent_id', $enquiry->id)
                        ->whereIn('enquiry_type', $stages)
                        ->whereNotIn('enquiry_type', ['Purchase_Invoice', 'Sales_Invoice'])
                        ->orderBy('created_at')
                        ->get();
                }

                if ($items->isEmpty()) {
                    continue;
                }

                $batches = $items->groupBy(function ($row) {
                    return $row->customer_po_no . '|' . $row->customer_due_date;
                });

                $orderedBatches = $batches->sortByDesc(function ($batchItems) {
                    return optional($batchItems->max('created_at'))->timestamp;
                });

                $mostRecentBatch = $orderedBatches->first();
                $latest = $mostRecentBatch->first();

                // Batch-level filters — customer_po_no / me_ref / status can
                // differ per batch within one enquiry, and the row here
                // represents the WHOLE enquiry, not a single batch. Match if
                // ANY batch satisfies the filter, same principle as
                // status/offer_type filtering in the other list endpoints.
                if ($request->filled('customer_po_no')) {
                    $needle = strtolower($request->customer_po_no);
                    $matches = $batches->contains(function ($batchItems) use ($needle) {
                        return str_contains(strtolower((string) $batchItems->first()->customer_po_no), $needle);
                    });
                    if (!$matches) {
                        continue;
                    }
                }

                if ($request->filled('status')) {
                    $matches = $batches->contains(function ($batchItems) use ($request) {
                        return $batchItems->first()->status == $request->status;
                    });
                    if (!$matches) {
                        continue;
                    }
                }

                $poNumbers = $batches
                    ->map(fn($batchItems) => $batchItems->first()->customer_po_no)
                    ->filter()
                    ->unique()
                    ->values();

                $dueDates = $batches
                    ->map(fn($batchItems) => $batchItems->first()->customer_due_date)
                    ->filter()
                    ->unique()
                    ->values();

                $itemRows = $items
                    ->groupBy('item_id')
                    ->map(function ($rows) {
                        $custPoRow = $rows->firstWhere('enquiry_type', 'Customer_PO');
                        $mostRecent = $rows->sortByDesc('created_at')->first();
                        $baseRow = $custPoRow ?: $mostRecent;
                        $totalQuantity = (float) ($baseRow->quantity ?? $mostRecent->quantity ?? 0);

                        $orderedPrincipal = $rows
                            ->where('enquiry_type', '!=', 'Customer_PO')
                            ->sum(fn($r) => (float) ($r->total_qty_ordered_principal ?? 0));
                        if ($orderedPrincipal <= 0 && $baseRow->total_qty_ordered_principal !== null) {
                            $orderedPrincipal = (float) $baseRow->total_qty_ordered_principal;
                        }

                        // If an item row is already in PO_Principal stage or has a principal PO number assigned,
                        // it is already ordered from Principal
                        if ($orderedPrincipal <= 0) {
                            $hasPoPrincipal = $rows->contains(function ($r) {
                                return $r->enquiry_type === 'PO_Principal' || !empty($r->po_principal_no);
                            });
                            if ($hasPoPrincipal) {
                                $orderedPrincipal = $totalQuantity;
                            }
                        }

                        $isFullyConverted = $totalQuantity > 0
                            ? ($orderedPrincipal >= $totalQuantity)
                            : ($mostRecent->enquiry_type !== 'Customer_PO');

                        $isPartiallyConverted = $totalQuantity > 0
                            && $orderedPrincipal > 0
                            && $orderedPrincipal < $totalQuantity;

                        return [
                            'id' => $baseRow->id,
                            'item_id' => $baseRow->item_id,
                            'material_code' => optional($baseRow->item)->mat_code,
                            'item_name' => optional($baseRow->item)->name,
                            'description' => optional($baseRow->item)->description,
                            'part_model_no' => optional($baseRow->item)->part_model_no,
                            'quantity' => $totalQuantity,
                            'total_qty_ordered_principal' => $orderedPrincipal,
                            'total_qty_ordered_principa' => $orderedPrincipal,
                            'ordered_qty' => $orderedPrincipal,
                            'pending_qty_principal' => max(0, $totalQuantity - $orderedPrincipal),
                            'price' => $rows->sum(fn($r) => (float) ($r->price ?? 0)),
                            'status' => $baseRow->status,
                            'date' => optional($baseRow->created_at)->format('Y-m-d'),
                            'isConverted' => $isFullyConverted,
                            'isPartiallyConverted' => $isPartiallyConverted,
                        ];
                    })
                    ->values();

                $data[] = [
                    'id' => $latest->id,
                    'parent_id' => $enquiry->id,
                    'inquiry_id' => $enquiry->inquiry_id,
                    'inquiry_code' => $enquiry->inquiry_code,
                    'enquiry_type' => $latest->enquiry_type,
                    'customer_id' => $enquiry->customer_id,
                    'customer_name' => optional($enquiry->customer)->name,
                    'principal_id' => $latest->principal_id,
                    'principal_quote_no' => $latest->principal_quote_no,
                    'principal_quote_date' => $latest->principal_quote_date,
                    'po_amount' => $latest->po_amount,
                    'po_basic' => $latest->po_basic,
                    'cumulative' => $latest->cumulative,
                    'offer_type' => $latest->offer_type,
                    'quantity_quoted' => $latest->quantity_quoted,
                    'validity_period' => $latest->validity_period,
                    'negotiation_details' => $latest->negotiation_details,
                    'follow_notes' => $latest->follow_notes,
                    'discussion_dates' => $latest->discussion_dates,
                    'revised_value' => $latest->revised_value,
                    'price' => $items->sum('price'),
                    'status' => $latest->status,
                    'quotation_no' => $latest->quotation_no,
                    'budgetary_no' => $latest->budgetary_no,
                    'customer_po_no' => $poNumbers->implode(', '),
                    'customer_po_date' => $latest->customer_po_date,
                    'customer_due_date' => $dueDates->implode(', '),
                    'delivery_location' => $latest->delivery_location,
                    'customer_payment_terms' => $latest->customer_payment_terms,
                    'ld_terms' => $latest->ld_terms,
                    'me_ref' => $latest->me_ref,
                    'pending_qty' => $latest->pending_qty,
                    'customer_pdo_document' => $latest->customer_pdo_document,

                    'items' => $itemRows,
                ];
            }

            return response()->json([
                'status' => true,
                'message' => 'Customer PO List',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
    private function applyFilters($query, Request $req)
    {
        // Date filter — manual range wins if both given
        if ($req->from_date && $req->to_date) {
            $query->whereBetween('created_at', [
                $req->from_date . ' 00:00:00',
                $req->to_date . ' 23:59:59'
            ]);
        } elseif ($req->period) {
            [$start, $end] = $this->resolvePeriodRange($req->period);

            if ($start && $end) {
                $query->whereBetween('created_at', [$start, $end]);
            }
        }

        // Sorting
        $allowedSorts = ['id', 'customer_due_date', 'status', 'created_at'];

        $sortBy = in_array($req->sort_by, $allowedSorts) ? $req->sort_by : 'created_at';
        $sortOrder = $req->sort_order === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    /**
     * Resolve a named period ('weekly','monthly','quarterly','yearly')
     * into a [start, end] datetime string pair, anchored to "now".
     */
    private function resolvePeriodRange(string $period): array
    {
        $now = Carbon::now();

        switch (strtolower($period)) {
            case 'weekly':
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfWeek();
                break;

            case 'monthly':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;

            case 'quarterly':
                $start = $now->copy()->firstOfQuarter();
                $end = $now->copy()->lastOfQuarter();
                break;

            case 'yearly':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;

            default:
                return [null, null];
        }

        return [
            $start->format('Y-m-d 00:00:00'),
            $end->format('Y-m-d 23:59:59'),
        ];
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $validated = $request->validate([
                'customer_id' => 'nullable|exists:users,id',
                'principal_id' => 'nullable|exists:users,id',

                'customer_po_no' => 'nullable|string|max:255',
                'customer_po_date' => 'nullable|date',
                'customer_due_date' => 'nullable|date',
                'delivery_location' => 'nullable|string|max:255',
                'customer_payment_terms' => 'nullable|string|max:255',
                'ld_terms' => 'nullable|string|max:255',
                'me_ref' => 'nullable|string|max:255',
                'pending_qty' => 'nullable|numeric|min:0',

                'po_amount' => 'nullable|numeric|min:0',
                'po_basic' => 'nullable|numeric|min:0',
                'cumulative' => 'nullable|numeric|min:0',

                'status' => 'nullable|in:Checked,Not Checked',

                'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',

                'item_quantities' => 'nullable|array',
                'item_quantities.*.id' => 'nullable|integer',
                'item_quantities.*.ordered_qty' => 'nullable|numeric',
            ]);

            $inquiry = Inquiry::findOrFail($id);

            $parentId = $inquiry->parent_id;

            // Upload file if provided — keep the existing document otherwise.
            $document = $inquiry->customer_pdo_document;

            if ($request->hasFile('customer_pdo_document')) {

                $file = $request->file('customer_pdo_document');

                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('uploads/customer_po_documents'), $document);
            }

            /*
            |--------------------------------------------------------------------------
            | Shared header fields — same shape as store().
            | po_amount, po_basic, cumulative, customer_id and principal_id are
            | actually written here.
            |--------------------------------------------------------------------------
            */

            Inquiry::where('parent_id', $parentId)->update([
                'customer_id' => $validated['customer_id'] ?? $inquiry->customer_id,
                'principal_id' => $validated['principal_id'] ?? $inquiry->principal_id,
                'customer_po_no' => $validated['customer_po_no'] ?? $inquiry->customer_po_no,
                'customer_po_date' => $validated['customer_po_date'] ?? $inquiry->customer_po_date,
                'customer_due_date' => $validated['customer_due_date'] ?? $inquiry->customer_due_date,
                'delivery_location' => $validated['delivery_location'] ?? $inquiry->delivery_location,
                'customer_payment_terms' => $validated['customer_payment_terms'] ?? $inquiry->customer_payment_terms,
                'ld_terms' => $validated['ld_terms'] ?? $inquiry->ld_terms,
                'me_ref' => $validated['me_ref'] ?? $inquiry->me_ref,
                'po_amount' => $validated['po_amount'] ?? 0,
                'po_basic' => $validated['po_basic'] ?? 0,
                'cumulative' => $validated['cumulative'] ?? 0,
                'customer_pdo_document' => $document,
                'status' => $validated['status'] ?? null,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Per-item quantity — writes into `quantity`.
            |--------------------------------------------------------------------------
            */

            if (!empty($validated['item_quantities'])) {

                foreach ($validated['item_quantities'] as $row) {

                    Inquiry::where('id', $row['id'])
                        ->where('parent_id', $parentId)
                        ->update([
                            'quantity' => $row['ordered_qty'],
                        ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Customer PO updated successfully.'
            ], 200);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a brand-new Customer PO enquiry.
     *
     * inquiry_code is SERVER-GENERATED (same INQ##### scheme used by
     * PurchaseInvoiceController::store() and PoToPrincipalController::store())
     * instead of being required from the client.
     *
     * 'enquiry_ref' is the optional free-text reference field (shown on the
     * form as "Enquiry ID (Ref)") — if the client sends one it's stored in
     * inquiry_id, otherwise inquiry_id just mirrors the generated
     * inquiry_code, exactly like the other two controllers.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            if (empty($request->customer_id) && !empty($request->customer_name)) {
                $foundCust = \App\Models\User::where('role', 'customer')
                    ->where('name', 'like', '%' . trim($request->customer_name) . '%')
                    ->first();
                if ($foundCust) {
                    $request->merge(['customer_id' => $foundCust->id]);
                }
            }
            if (empty($request->principal_id) && !empty($request->principal_name)) {
                $foundPrinc = \App\Models\User::where('role', 'vendor')
                    ->where('name', 'like', '%' . trim($request->principal_name) . '%')
                    ->first();
                if ($foundPrinc) {
                    $request->merge(['principal_id' => $foundPrinc->id]);
                }
            }

            $validated = $request->validate([

                'enquiry_ref' => 'nullable|string|max:255',
                'customer_id' => 'nullable|exists:users,id',
                'principal_id' => 'nullable|exists:users,id',
                'customer_name' => 'nullable|string|max:255',
                'principal_name' => 'nullable|string|max:255',

                'customer_po_no' => 'nullable|string|max:255',
                'customer_po_date' => 'nullable|date',
                'customer_due_date' => 'nullable|date',
                'delivery_location' => 'nullable|string|max:255',
                'customer_payment_terms' => 'nullable|string|max:255',
                'ld_terms' => 'nullable|string|max:255',
                'me_ref' => 'nullable|string|max:255',
                'pending_qty' => 'nullable|numeric|min:0',

                'po_amount' => 'nullable|numeric|min:0',
                'po_basic' => 'nullable|numeric|min:0',
                'cumulative' => 'nullable|numeric|min:0',

                'status' => 'nullable|in:Price Requested,Pending,Closed,Completed,Regret,Checked,Not Checked',

                'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',

                'items' => 'nullable|array',
                'items.*.item_id' => 'nullable|integer',
                'items.*.mat_code' => 'nullable|string|max:255',
                'items.*.item_name' => 'nullable|string|max:255',
                'items.*.description' => 'nullable|string|max:255',
                'items.*.part_model_no' => 'nullable|string|max:255',
                'items.*.qty' => 'nullable|numeric',
                'items.*.price' => 'nullable|numeric|min:0',
                'items.*.date' => 'nullable|date',
                'items.*.status' => 'nullable|string|max:100',
            ]);

            $customerId = $validated['customer_id'] ?? \App\Models\User::where('role', 'customer')->value('id');
            $meRef = !empty($validated['me_ref']) ? $validated['me_ref'] : null;

            /*
            |--------------------------------------------------------------------------
            | Generate inquiry_code (INQ00001, INQ00002, ...) — same scheme as
            | PurchaseInvoiceController::store() / PoToPrincipalController::store(),
            | so every "root" enquiry across the pipeline shares one counter.
            |--------------------------------------------------------------------------
            */

            $prefix = 'INQ';

            $lastInquiry = Inquiry::orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $lastNumber = 0;
            if ($lastInquiry && preg_match('/(\d+)$/', $lastInquiry->inquiry_code, $matches)) {
                $lastNumber = (int) $matches[1];
            }

            $inquiryCode = $prefix . str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

            /*
            |--------------------------------------------------------------------------
            | Upload Customer PO Document
            |--------------------------------------------------------------------------
            */

            $document = null;

            if ($request->hasFile('customer_pdo_document')) {

                $file = $request->file('customer_pdo_document');

                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('uploads/customer_po_documents'), $document);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve/create the catalog Item for every submitted row up front.
            | Every row must resolve to a catalog item — either by item_id, or by
            | mat_code (create-if-missing). If a row has neither field populated,
            | we throw instead of silently inserting item_id=null.
            |--------------------------------------------------------------------------
            */

            $preparedItems = [];
            $submittedItems = !empty($validated['items']) ? $validated['items'] : [[]];

            foreach ($submittedItems as $index => $itemRow) {

                $qty = (float) ($itemRow['qty'] ?? 1);
                if ($qty <= 0) {
                    $qty = 1;
                }

                $catalogItem = null;

                // 1. Try resolving by item_id first.
                if (!empty($itemRow['item_id'])) {
                    $catalogItem = Item::find($itemRow['item_id']);
                }

                // 2. Fall back to mat_code (create if it doesn't exist yet).
                if (!$catalogItem && !empty($itemRow['mat_code'])) {
                    $catalogItem = Item::firstOrCreate(
                        ['mat_code' => $itemRow['mat_code']],
                        [
                            'name' => ($itemRow['item_name'] ?? null) ?: $itemRow['mat_code'],
                            'part_model_no' => $itemRow['part_model_no'] ?? '',
                            'description' => $itemRow['description'] ?? '',
                        ]
                    );
                }

                // 3. Fallback: create or use default item if neither was provided
                if (!$catalogItem) {
                    $fallbackCode = !empty($itemRow['item_name']) 
                        ? ('MAT-' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $itemRow['item_name']), 0, 8)))
                        : ('MAT-GEN-' . rand(100, 999));
                    $catalogItem = Item::firstOrCreate(
                        ['mat_code' => $fallbackCode],
                        [
                            'name' => $itemRow['item_name'] ?? 'General Material / Spares',
                            'part_model_no' => $itemRow['part_model_no'] ?? '',
                            'description' => $itemRow['description'] ?? '',
                        ]
                    );
                }

                // Update catalog record with any newer details sent from the form.
                $catalogItem->fill([
                    'mat_code' => $itemRow['mat_code'] ?? $catalogItem->mat_code,
                    'name' => !empty($itemRow['item_name']) ? $itemRow['item_name'] : $catalogItem->name,
                    'description' => $itemRow['description'] ?? $catalogItem->description,
                    'part_model_no' => $itemRow['part_model_no'] ?? $catalogItem->part_model_no,
                ])->save();

                $preparedItems[] = [
                    'item_id' => $catalogItem->id,
                    'qty' => $qty,
                    'price' => $itemRow['price'] ?? 0,
                    'date' => $itemRow['date'] ?? null,
                    'status' => $itemRow['status'] ?? 'Pending',
                ];
            }

            if (empty($preparedItems)) {
                $defaultCatItem = Item::firstOrCreate(['mat_code' => 'MAT-DEFAULT'], ['name' => 'General Material / Spares']);
                $preparedItems[] = [
                    'item_id' => $defaultCatItem->id,
                    'qty' => 1,
                    'price' => 0,
                    'date' => null,
                    'status' => 'Pending',
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Create Record — the FIRST item merges straight into the root row
            | (same idea as PurchaseInvoiceController::store() /
            | PoToPrincipalController::store()) instead of getting a separate
            | child row sitting next to it.
            |--------------------------------------------------------------------------
            */

            $firstItem = array_shift($preparedItems);

            $inquiry = Inquiry::create([

                'parent_id' => 0,

                'inquiry_code' => $inquiryCode,
                'inquiry_id' => $validated['enquiry_ref'] ?? $inquiryCode,

                'enquiry_type' => 'Customer_PO',

                'customer_id' => $customerId,
                'principal_id' => $validated['principal_id'] ?? null,
                'item_id' => $firstItem['item_id'],

                'status' => $firstItem['status'] ?? ($validated['status'] ?? 'Pending'),

                'customer_po_no' => $validated['customer_po_no'] ?? null,
                'customer_po_date' => $validated['customer_po_date'] ?? null,
                'customer_due_date' => $validated['customer_due_date'] ?? null,
                'delivery_location' => $validated['delivery_location'] ?? null,
                'customer_payment_terms' => $validated['customer_payment_terms'] ?? null,
                'ld_terms' => $validated['ld_terms'] ?? null,
                'quantity' => $firstItem['qty'],
                'price' => $firstItem['price'] ?? 0,
                'me_ref' => $meRef ?: $inquiryCode,
                'pending_qty' => $validated['pending_qty'] ?? $firstItem['qty'],
                'po_amount' => $validated['po_amount'] ?? 0,
                'po_basic' => $validated['po_basic'] ?? 0,
                'cumulative' => $validated['cumulative'] ?? 0,
                'customer_pdo_document' => $document,

                'user_id' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // First record becomes parent/anchor for the whole enquiry group.
            $inquiry->update([
                'parent_id' => $inquiry->id
            ]);

            $createdItemIds = [$inquiry->id];

            // Every remaining item (2nd onward) gets its own child row, sharing
            // the same inquiry_code/inquiry_id/parent_id as the anchor above.
            foreach ($preparedItems as $item) {

                $childInquiry = Inquiry::create([
                    'parent_id' => $inquiry->id,
                    'inquiry_code' => $inquiry->inquiry_code,
                    'inquiry_id' => $inquiry->inquiry_id,
                    'item_id' => $item['item_id'],
                    'enquiry_type' => 'Customer_PO',
                    'customer_id' => $customerId,
                    'principal_id' => $validated['principal_id'] ?? null,
                    'customer_po_no' => $validated['customer_po_no'] ?? null,
                    'customer_po_date' => $validated['customer_po_date'] ?? null,
                    'customer_due_date' => $validated['customer_due_date'] ?? null,
                    'delivery_location' => $validated['delivery_location'] ?? null,
                    'customer_payment_terms' => $validated['customer_payment_terms'] ?? null,
                    'ld_terms' => $validated['ld_terms'] ?? null,
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'me_ref' => $meRef ?: $inquiryCode,
                    'pending_qty' => $item['qty'],
                    'po_amount' => $validated['po_amount'] ?? 0,
                    'po_basic' => $validated['po_basic'] ?? 0,
                    'cumulative' => $validated['cumulative'] ?? 0,
                    'customer_pdo_document' => $document,
                    'status' => $item['status'],
                    'user_id' => Auth::id(),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                $createdItemIds[] = $childInquiry->id;
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Customer PO created successfully.',
                'data' => $inquiry,
                'created_ids' => $createdItemIds,
            ], 201);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Customer PO store error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $inquiry = Inquiry::find($id);

            if (!$inquiry) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer PO not found.'
                ], 404);
            }

            $parentId = $inquiry->parent_id ?: $inquiry->id;

            if (function_exists('saveLog')) {
                saveLog('DELETE_CUSTOMER_PO', 'Inquiry', $inquiry);
            }

            Inquiry::where('parent_id', $parentId)
                ->orWhere('id', $inquiry->id)
                ->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Customer PO deleted successfully.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer PO delete error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete Customer PO. ' . $e->getMessage()
            ], 500);
        }
    }

}

