<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PoToPrincipalController extends Controller
{
    public function convertTopoprincipal(Request $request, $id)
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
                'po_principal_no' => 'nullable|string|max:255',
                'principal_co_no' => 'nullable|string|max:255',
                'principal_co_date' => 'nullable|date',
                'expected_delivery_date' => 'nullable|date',
                'promise_date' => 'nullable|date',
                'po_date' => 'nullable|date',
                'customer_name' => 'nullable|string|max:255',
                'basic_amount' => 'nullable|numeric|min:0',
                'pf_amount' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'po_amount' => 'nullable|numeric|min:0',
                'gst_amount' => 'nullable|numeric|min:0',
                'total_with_gst' => 'nullable|numeric|min:0',
                'total_po_with_gst' => 'nullable|numeric|min:0',
                'total_qty_ordered_principal' => 'nullable|numeric|min:0',
                'qty_received_principal' => 'nullable|numeric|min:0',
                'released_date' => 'nullable|date',
                'status' => 'nullable|string|max:100|in:Pending,Checked,Ordered Placed,Partially Placed',
                'principal_po_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'nullable|integer',
                'item_quantities' => 'nullable|array',
                'item_quantities.*.id' => 'nullable|integer',
                'item_quantities.*.ordered_qty' => 'nullable|numeric',
            ]);

            // Resolve the typed customer name to a customer_id, same pattern
            // used in store()/update() — nullable, falls back to whatever
            // customer_id the source item already carries.
            $conversionCustomerId = null;
            if (!empty($validated['customer_name'])) {
                $resolvedCustomer = User::where('name', $validated['customer_name'])->first();
                if ($resolvedCustomer) {
                    $conversionCustomerId = $resolvedCustomer->id;
                }
            }

            $conversionBasicAmount = $validated['basic_amount'] ?? 0;
            $conversionPfAmount = $validated['pf_amount'] ?? 0;
            $conversionFreightAmount = $validated['freight_amount'] ?? 0;
            $conversionPoAmount = $validated['po_amount'] ?? 0;
            $conversionGstAmount = $validated['gst_amount'] ?? 0;
            $conversionTotalWithGst = isset($validated['total_with_gst']) && (float) $validated['total_with_gst'] > 0
                ? (float) $validated['total_with_gst']
                : (isset($validated['total_po_with_gst']) && (float) $validated['total_po_with_gst'] > 0
                    ? (float) $validated['total_po_with_gst']
                    : round($conversionPoAmount + ($conversionPoAmount * $conversionGstAmount / 100), 2));

            // Single shared timestamp for every row touched by THIS
            // request — guarantees the "first conversion" (UPDATE) rows
            // and the "later conversion" (INSERT) rows all get the exact
            // same updated_at, down to the microsecond, regardless of how
            // long the request takes or how many rows are processed. This
            // is what poprincipalList() groups batches by, so this
            // eliminates any risk of one real conversion event splitting
            // into multiple rows on that list.
            $batchTimestamp = now();

            $document = null;
            if ($request->hasFile('principal_po_document')) {
                $file = $request->file('principal_po_document');
                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('uploads/principal_po_documents'), $document);
            }

            // Only the items actually selected for this conversion — falls
            // back to just the clicked row ($id) if the frontend didn't
            // send item_ids.
            $itemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$id];

            $selectedItems = Inquiry::whereIn('id', $itemIds)
                ->get([
                    'id',
                    'parent_id',
                    'quantity',
                    'pending_qty_principal',
                    'total_qty_ordered_principal',
                    'enquiry_type',
                    'inquiry_code',
                    'inquiry_id',
                    'item_id',
                    'concerned_person',
                    'concerned_area',
                    'customer_id',
                    'principal_id',
                    'customer_po_no',
                    'customer_due_date',
                    'me_ref',
                    'po_amount',
                    'po_basic',
                    'cumulative',
                    'customer_pdo_document',
                    'updated_at'
                ]);

            if ($selectedItems->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'No matching items found for the given item_ids.',
                ], 422);
            }

            // Per-item batches: prefer item_quantities; fall back to spreading
            // the single flat total_qty_ordered_principal across every
            // selected item (old single-item-form behavior).
            //
            // NOTE: if the frontend sends multiple item_ids but no
            // item_quantities array (or an empty one), this fallback will
            // apply the SAME flat total_qty_ordered_principal to every
            // selected item — which is almost never what's wanted for a
            // multi-item conversion. We explicitly reject that ambiguous
            // case below instead of silently guessing.
            if (empty($validated['item_quantities']) && count($itemIds) > 1) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Multiple items selected but no per-item quantities (item_quantities) were provided.',
                ], 422);
            }

            $batches = !empty($validated['item_quantities'])
                ? $validated['item_quantities']
                : $selectedItems->map(fn($item) => [
                    'id' => $item->id,
                    'ordered_qty' => $validated['total_qty_ordered_principal'] ?? 0,
                ])->toArray();

            // NOTE: the "ordered qty exceeds remaining unconverted qty" check
            // that used to run here has been removed at the user's request.
            // Nothing downstream depends on it — $batches feeds straight into
            // the conversion loop below. Be aware that without this guard,
            // pending_qty_principal can go negative if a caller ever submits
            // more qty than is actually left on a line (max(0, ...) in the
            // loop below only floors the value being written, it does not
            // stop an over-large request from being accepted).
            $createdRows = [];

            foreach ($batches as $row) {
                $item = $selectedItems->firstWhere('id', $row['id']);
                if (!$item)
                    continue;

                $batchQty = (float) $row['ordered_qty'];
                if ($batchQty <= 0)
                    continue;

                $isFirstConversion = $item->enquiry_type !== 'PO_Principal';
                $isFirstConversion = ((float) ($item->total_qty_ordered_principal ?? 0)) <= 0 && $item->enquiry_type === 'Customer_PO';
                $remainingBefore = $isFirstConversion
                    ? (float) $item->quantity
                    : (float) $item->pending_qty_principal;
                $itemRemaining = max(0, $remainingBefore - $batchQty);

                if ($isFirstConversion) {
                    // FIRST conversion for this line: update the ORIGINAL row
                    // in place, storing ONLY this batch's own qty.
                    //
                    // IMPORTANT: pending_qty_principal MUST be set here.
                    // This is the only place that establishes the real
                    // "unconverted balance" after a first conversion — if
                    // it's left unset, every later conversion attempt on
                    // this line will read a stale/zero value and incorrectly
                    // block valid requests (or wrongly allow invalid ones).
                    Inquiry::where('id', $item->id)
                        ->update([
                            'enquiry_type' => 'PO_Principal',
                            'quantity' => $item->quantity,
                            'po_principal_no' => $validated['po_principal_no'] ?? null,
                            'principal_co_no' => $validated['principal_co_no'] ?? null,
                            'principal_co_date' => $validated['principal_co_date'] ?? null,
                            'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                            'promise_date' => $validated['promise_date'] ?? null,
                            'po_date' => $validated['po_date'] ?? null,
                            'customer_id' => $conversionCustomerId ?? $item->customer_id,
                            'basic_amount' => $conversionBasicAmount,
                            'pf_amount' => $conversionPfAmount,
                            'freight_amount' => $conversionFreightAmount,
                            'po_amount_principal' => $conversionPoAmount,
                            'gst_amount' => $conversionGstAmount,
                            'total_with_gst' => $conversionTotalWithGst,
                            'total_qty_ordered_principal' => $batchQty,
                            'qty_received_principal' => $validated['qty_received_principal'] ?? 0,
                            'pending_qty_principal' => $itemRemaining,
                            'released_date' => $validated['released_date'] ?? null,
                            'principal_po_document' => $document,
                            'status' => $validated['status'] ?? null,
                            'updated_by' => Auth::id(),
                            'updated_at' => $batchTimestamp,
                        ]);

                    $createdRows[] = $item->id;
                } else {
                    // LATER conversion for this line: original row untouched
                    // aside from pending_qty_principal — INSERT a new row
                    // holding just this batch's own qty. 
                    $newRow = Inquiry::create([
                        'inquiry_code' => $item->inquiry_code,
                        'parent_id' => $item->parent_id ?: $item->id,
                        'inquiry_id' => $item->inquiry_id,
                        'item_id' => $item->item_id,
                        'concerned_person' => $item->concerned_person,
                        'concerned_area' => $item->concerned_area,
                        'customer_id' => $conversionCustomerId ?? $item->customer_id,
                        'principal_id' => $item->principal_id,
                        'quantity' => $item->quantity ?? $batchQty,
                        'customer_po_no' => $item->customer_po_no,
                        'customer_due_date' => $item->customer_due_date,
                        'me_ref' => $item->me_ref,
                        'po_amount' => $item->po_amount,
                        'po_basic' => $item->po_basic,
                        'cumulative' => $item->cumulative,
                        'customer_pdo_document' => $item->customer_pdo_document,
                        'enquiry_type' => 'PO_Principal',
                        'po_principal_no' => $validated['po_principal_no'] ?? null,
                        'principal_co_no' => $validated['principal_co_no'] ?? null,
                        'principal_co_date' => $validated['principal_co_date'] ?? null,
                        'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                        'promise_date' => $validated['promise_date'] ?? null,
                        'po_date' => $validated['po_date'] ?? null,
                        'basic_amount' => $conversionBasicAmount,
                        'pf_amount' => $conversionPfAmount,
                        'freight_amount' => $conversionFreightAmount,
                        'po_amount_principal' => $conversionPoAmount,
                        'gst_amount' => $conversionGstAmount,
                        'total_with_gst' => $conversionTotalWithGst,
                        'total_qty_ordered_principal' => $batchQty,
                        'qty_received_principal' => $validated['qty_received_principal'] ?? 0,
                        'pending_qty_principal' => 0,
                        'released_date' => $validated['released_date'] ?? null,
                        'principal_po_document' => $document,
                        'status' => $validated['status'] ?? null,
                        'updated_by' => Auth::id(),
                        'created_by' => Auth::id(),
                        'created_at' => $batchTimestamp,
                        'updated_at' => $batchTimestamp,
                    ]);

                    $createdRows[] = $newRow->id;

                    // Keep the ORIGINAL row's pending_qty_principal
                    // authoritative so the *next* conversion of this same line
                    // reads the correct remainder.
                    Inquiry::where('id', $item->id)
                        ->update([
                            'pending_qty_principal' => $itemRemaining,
                            'updated_at' => $item->updated_at,
                        ]);
                }
            }

            if (empty($createdRows)) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'No rows were converted — all requested quantities were zero.',
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'PO to Principal created successfully.',
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
            Log::error('PO to Principal Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * NOTE: this now accepts Request $request. Previously it took no
     * arguments at all, which meant applyFilters() (search/date/sort)
     * was dead code — there was no request to feed it and it was never
     * even called. That was the root cause of "filters not working".
     */
    public function poprincipalList(Request $request)
    {
        try {

            $stages = ['PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

            // Build the parent query, then run it through applyFilters()
            // BEFORE ->get(). applyFilters() filters on top-level columns
            // (enquiry_type, po_principal_no, me_ref, po_principal_status,
            // created_at) and sorts by $allowedSorts, so it operates on
            // this parent-level query.
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

                $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
                    ->where('parent_id', $enquiry->id)
                    ->where('enquiry_type', 'PO_Principal')
                    ->get();

                if ($items->isEmpty()) {
                    $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
                        ->where('parent_id', $enquiry->id)
                        ->whereIn('enquiry_type', $stages)
                        ->where('enquiry_type', '!=', 'Purchase_Invoice')
                        ->get();
                }

                if ($items->isEmpty()) {
                    continue;
                }

                // ONE ROW PER CONVERSION EVENT. Group by po_principal_no
                // PLUS the updated_at timestamp (to the second) — grouping
                // by po_principal_no alone merges unrelated conversions
                // into one row whenever two separate "PO to Principal"
                // submissions happen to reuse the same PO number text.
                // We use updated_at (not created_at) because
                // convertTopoprincipal() now stamps every row it touches
                // in a single request — both updated rows AND newly
                // inserted rows — with the exact same shared timestamp, so
                // updated_at reliably identifies "written together in this
                // one conversion" regardless of whether a given row was an
                // UPDATE (existing row, old created_at) or an INSERT (new
                // row, fresh created_at).
                $batches = $items->groupBy(function ($row) {
                    $stamp = optional($row->updated_at)->format('Y-m-d H:i:s');
                    return $row->po_principal_no . '|' . $stamp;
                });

                // Oldest conversion first, so "1st conversion" really shows
                // above "2nd conversion" etc. Sorted on the same field
                // (updated_at) used for grouping, for consistency.
                $orderedBatches = $batches->sortBy(function ($batchItems) {
                    return optional($batchItems->min('updated_at'))->timestamp;
                });

                foreach ($orderedBatches as $batchItems) {

                    $sourceRow = $batchItems->first();

                    $data[] = [
                        'id' => $sourceRow->id,
                        'parent_id' => $enquiry->id,
                        'inquiry_id' => $enquiry->inquiry_id,
                        'inquiry_code' => $enquiry->inquiry_code,
                        'enquiry_type' => $sourceRow->enquiry_type,
                        'customer_id' => $enquiry->customer_id,
                        'customer_name' => optional($enquiry->customer)->name,
                        'principal_id' => $sourceRow->principal_id,
                        'me_ref' => $enquiry->me_ref,
                        'customer_po_no' => $enquiry->customer_po_no,
                        'customer_due_date' => $enquiry->customer_due_date,
                        'pending_qty' => $enquiry->pending_qty,
                        'po_principal_no' => $sourceRow->po_principal_no,
                        'principal_co_no' => $sourceRow->principal_co_no,
                        'principal_co_date' => $sourceRow->principal_co_date,
                        'expected_delivery_date' => $sourceRow->expected_delivery_date,
                        'promise_date' => $sourceRow->promise_date,
                        'original_promise_date' => $sourceRow->original_promise_date,
                        'latest_followup_date' => $sourceRow->latest_followup_date,
                        'next_followup_date' => $sourceRow->next_followup_date,
                        'principal_production_status' => $sourceRow->principal_production_status,
                        'po_date' => $sourceRow->po_date,
                        'basic_amount' => $sourceRow->basic_amount,
                        'pf_amount' => $sourceRow->pf_amount,
                        'freight_amount' => $sourceRow->freight_amount,
                        'po_amount' => $sourceRow->po_amount_principal,
                        'gst_amount' => $sourceRow->gst_amount,
                        'total_with_gst' => $sourceRow->total_with_gst,
                        'total_qty_ordered_principal' => $batchItems->sum('total_qty_ordered_principal'),
                        'qty_received_principal' => $sourceRow->qty_received_principal,
                        'pending_qty_principal' => $sourceRow->pending_qty_principal,
                        'released_date' => $sourceRow->released_date,
                        'principal_po_document' => $sourceRow->principal_po_document,
                        'status' => $sourceRow->status,
                        'is_advance_principal_order' => (bool) $sourceRow->is_advance_principal_order,
                        'advance_order_reason' => $sourceRow->advance_order_reason,
                        'expected_customer_name' => $sourceRow->expected_customer_name,
                        'unallocated_qty' => (float) $sourceRow->unallocated_qty,
                        'is_combined_principal_order' => (bool) $sourceRow->is_combined_principal_order,
                        'order_closure_status' => $sourceRow->order_closure_status ?? 'In Progress',

                        'items' => $batchItems->map(function ($row) {
                            $totalOrdered = (float) ($row->total_qty_ordered_principal ?? 0);
                            $qtyInvoiced = (float) ($row->qty_invoiced ?? 0);
                            $isFully = $totalOrdered > 0 ? ($qtyInvoiced >= $totalOrdered) : ($row->enquiry_type !== 'PO_Principal');
                            $isPartial = $totalOrdered > 0 && $qtyInvoiced > 0 && $qtyInvoiced < $totalOrdered;

                            return [
                                'id' => $row->id,
                                'item_id' => $row->item_id,
                                'material_code' => optional($row->item)->mat_code,
                                'item_name' => optional($row->item)->name,
                                'description' => optional($row->item)->description,
                                'quantity' => $row->total_qty_ordered_principal ?: $row->quantity,
                                'customer_qty_order' => $row->total_qty_ordered_principal ?: $row->quantity,
                                'total_qty_ordered_principal' => $row->total_qty_ordered_principal,
                                'total_qty_ordered_principa' => $row->total_qty_ordered_principal,
                                'ordered_qty' => $row->total_qty_ordered_principal,
                                'pending_qty_principal' => $row->pending_qty_principal,
                                'qty_invoiced' => $row->qty_invoiced,
                                'price' => $row->price,
                                'status' => $row->status,
                                'date' => optional($row->created_at)->format('Y-m-d'),
                                'isConverted' => $isFully,
                                'isPartiallyConverted' => $isPartial,
                            ];
                        })->values(),
                    ];
                }
            }

            // Re-apply the requested sort to the final grouped $data array.
            // The DB-level orderBy() inside applyFilters() only sorts the
            // *parent* enquiries fetched above — but the loop then explodes
            // each parent into one row per conversion batch, and reorders
            // those batches by updated_at (oldest-first) independently of
            // whatever order the parents came in. That means a DB-level
            // sort on e.g. po_principal_status or created_at can get lost
            // by the time $data is assembled. Sorting $data itself here
            // guarantees the final response actually reflects sort_by /
            // sort_order.
            $allowedSorts = ['id', 'promise_date', 'po_principal_status', 'created_at'];
            $sortBy = in_array($request->sort_by, $allowedSorts) ? $request->sort_by : 'created_at';
            $sortOrder = $request->sort_order === 'asc' ? 'asc' : 'desc';

            usort($data, function ($a, $b) use ($sortBy, $sortOrder) {
                $valA = $a[$sortBy] ?? null;
                $valB = $b[$sortBy] ?? null;

                if ($valA == $valB)
                    return 0;

                $result = $valA <=> $valB;

                return $sortOrder === 'asc' ? $result : -$result;
            });

            return response()->json([
                'status' => true,
                'message' => 'PO to Principal List',
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
        // Search
        if ($req->search) {
            $query->where(function ($q) use ($req) {
                $q->where('enquiry_type', 'like', "%{$req->search}%")
                    ->orWhere('po_principal_no', 'like', "%{$req->search}%")
                    ->orWhere('me_ref', 'like', "%{$req->search}%")
                    ->orWhere('po_principal_status', 'like', "%{$req->search}%");
            });
        }

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

        // Sorting (parent-level query — final response order is re-applied
        // to $data in poprincipalList() after batch-grouping; see note
        // there for why both are needed)
        $allowedSorts = ['id', 'promise_date', 'po_principal_status', 'created_at'];

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
                'po_principal_no' => 'nullable|string|max:255',
                'principal_co_no' => 'nullable|string|max:255',
                'principal_co_date' => 'nullable|date',
                'expected_delivery_date' => 'nullable|date',
                'enquiry_id' => 'nullable|string|max:255',
                'promise_date' => 'nullable|date',
                'po_date' => 'nullable|date',
                'customer_name' => 'nullable|string|max:255',
                'basic_amount' => 'nullable|numeric|min:0',
                'pf_amount' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'po_amount' => 'nullable|numeric|min:0',
                'gst_amount' => 'nullable|numeric|min:0',
                'total_with_gst' => 'nullable|numeric|min:0',
                'total_po_with_gst' => 'nullable|numeric|min:0',
                'total_qty_ordered_principal' => 'nullable|numeric|min:0',
                'qty_received_principal' => 'nullable|numeric|min:0',
                'released_date' => 'nullable|date',
                'status' => 'nullable|in:PENDING,DISPATCH PARTIAL,DISPATCH FULL,Ordered Placed,Partially Placed,Checked',
                'principal_po_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'integer|exists:inquiries,id',
                'items' => 'nullable|array',
                'items.*.id' => 'nullable|integer|exists:inquiries,id',
                'items.*.item_id' => 'nullable|integer|exists:items,id',
                'items.*.mat_code' => 'nullable|string|max:255',
                'items.*.item_name' => 'nullable|string|max:255',
                'items.*.description' => 'nullable|string|max:255',
                'items.*.part_model_no' => 'nullable|string|max:255',
                'items.*.qty' => 'nullable|numeric|min:0',
                'items.*.price' => 'nullable|numeric|min:0',
                'items.*.date' => 'nullable|date',
                'items.*.status' => 'nullable|string|max:100',
            ]);

            $inquiry = Inquiry::findOrFail($id);

            $document = $inquiry->principal_po_document;

            if ($request->hasFile('principal_po_document')) {

                $file = $request->file('principal_po_document');

                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('uploads/principal_po_documents'), $document);
            }

            $itemRows = $validated['items'] ?? [];

            // Total ordered qty: prefer the sum of the per-item qty values (the
            // Items table is now the source of truth for quantity), falling
            // back to the old flat total_qty_ordered_principal field for any
            // caller that doesn't send items.
            $itemsQtySum = collect($itemRows)->sum(fn($row) => (float) ($row['qty'] ?? 0));
            $totalQtyOrdered = $itemsQtySum > 0
                ? $itemsQtySum
                : (float) ($validated['total_qty_ordered_principal'] ?? 0);

            // if ($totalQtyOrdered < 1) {
            //     DB::rollBack();
            //     return response()->json([
            //         'success' => false,
            //         'errors' => [
            //             'total_qty_ordered_principal' => ['The total qty ordered principal field must be at least 1.'],
            //         ],
            //     ], 422);
            // }

            $qtyReceived = $validated['qty_received_principal'] ?? 0;

            // NOTE: pending_qty_principal is intentionally NOT recalculated
            // or touched here. That field means "remaining unconverted qty"
            // and is owned exclusively by convertTopoprincipal() — it must
            // never be overwritten based on qty_received_principal, which
            // is a completely different concept (undelivered-from-principal
            // qty). Mixing the two here previously corrupted the balance
            // convertTopoprincipal() relies on for its remaining-qty checks.

            // Same fix as convertTopoprincipal(): scope to the items that
            // actually belong to this PO-to-Principal batch, not every row
            // under the enquiry's parent_id.
            $itemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$id];

            $availableQty = (float) Inquiry::whereIn('id', $itemIds)->sum('quantity');

            if ($totalQtyOrdered > $availableQty && empty($itemRows)) {
                // Only enforced when items[] isn't driving the numbers — once
                // items[] is present, each row's own qty is authoritative and
                // this comparison no longer applies cleanly.
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Ordered qty ({$totalQtyOrdered}) exceeds the qty available to convert ({$availableQty}) for the selected items.",
                ], 422);
            }

            // Resolve the typed customer name back to a customer_id, same
            // pattern used for principal resolution elsewhere. Falls back to
            // the row's existing customer_id if no match / nothing sent.
            $customerId = $inquiry->customer_id;
            if (!empty($validated['customer_name'])) {
                $resolvedCustomer = User::where('name', $validated['customer_name'])->first();
                if ($resolvedCustomer) {
                    $customerId = $resolvedCustomer->id;
                }
            }

            $basicAmount = $validated['basic_amount'] ?? 0;
            $pfAmount = $validated['pf_amount'] ?? 0;
            $freightAmount = $validated['freight_amount'] ?? 0;
            $poAmount = $validated['po_amount'] ?? 0;
            $gstAmount = $validated['gst_amount'] ?? 0;
            $totalWithGst = isset($validated['total_with_gst']) && (float) $validated['total_with_gst'] > 0
                ? (float) $validated['total_with_gst']
                : (isset($validated['total_po_with_gst']) && (float) $validated['total_po_with_gst'] > 0
                    ? (float) $validated['total_po_with_gst']
                    : round($poAmount + ($poAmount * $gstAmount / 100), 2));

            // ── Header-level fields, applied to every existing row in this
            // batch. total_qty_ordered_principal is deliberately NOT set here
            // when items[] is present — each item keeps its own qty via the
            // loop below instead of being flattened to one combined number.
            $headerUpdate = [
                'po_principal_no' => $validated['po_principal_no'] ?? null,
                'principal_co_no' => $validated['principal_co_no'] ?? $inquiry->principal_co_no,
                'principal_co_date' => $validated['principal_co_date'] ?? $inquiry->principal_co_date,
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? $inquiry->expected_delivery_date,
                'promise_date' => $validated['promise_date'] ?? $inquiry->promise_date,
                'inquiry_id' => $validated['enquiry_id'] ?? $inquiry->inquiry_id,
                'po_date' => $validated['po_date'] ?? $inquiry->po_date,
                'customer_id' => $customerId,
                'basic_amount' => $basicAmount,
                'pf_amount' => $pfAmount,
                'freight_amount' => $freightAmount,
                'po_amount_principal' => $poAmount,
                'gst_amount' => $gstAmount,
                'total_with_gst' => $totalWithGst,
                'qty_received_principal' => $qtyReceived ?? null,
                'released_date' => $validated['released_date'] ?? null,
                'principal_po_document' => $document ?? null,
                'status' => $validated['status'] ?? 'Pending',
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ];

            if (empty($itemRows) && $totalQtyOrdered > 0) {
                $headerUpdate['total_qty_ordered_principal'] = $totalQtyOrdered;
            }

            Inquiry::whereIn('id', $itemIds)->update($headerUpdate);

            // ── Per-item sync: update existing rows in place (including their
            // catalog Item — mat_code / item_name / description / part_model_no
            // — which the bulk update above never touches), and insert any
            // brand-new rows.
            $createdItemIds = [];
            $updatedItemIds = [];

            if (!empty($itemRows)) {

                $parentId = $inquiry->parent_id ?: $inquiry->id;

                foreach ($itemRows as $itemRow) {
                    $qty = (float) ($itemRow['qty'] ?? 0);
                    if ($qty <= 0)
                        continue;

                    $catalogItem = null;

                    if (!empty($itemRow['item_id'])) {
                        $catalogItem = Item::find($itemRow['item_id']);
                    }

                    if (!$catalogItem && !empty($itemRow['mat_code'])) {
                        $catalogItem = Item::firstOrCreate(
                            ['mat_code' => $itemRow['mat_code']],
                            [
                                'name' => $itemRow['item_name'] ?: $itemRow['mat_code'],
                                'part_model_no' => $itemRow['part_model_no'] ?? '',
                                'description' => $itemRow['description'] ?? '',
                            ]
                        );
                    }

                    if ($catalogItem) {
                        $catalogItem->fill([
                            'mat_code' => $itemRow['mat_code'] ?? $catalogItem->mat_code,
                            'name' => !empty($itemRow['item_name']) ? $itemRow['item_name'] : $catalogItem->name,
                            'description' => $itemRow['description'] ?? $catalogItem->description,
                            'part_model_no' => $itemRow['part_model_no'] ?? $catalogItem->part_model_no,
                        ])->save();
                    }

                    if (!empty($itemRow['id'])) {
                        // Existing item row — update in place, including the
                        // catalog link and its own qty/price/date/status.
                        Inquiry::where('id', $itemRow['id'])->update([
                            'item_id' => $catalogItem->id ?? null,
                            'quantity' => $qty,
                            'total_qty_ordered_principal' => $qty,
                            'price' => $itemRow['price'] ?? 0,
                            'inquiry_date' => $itemRow['date'] ?? null,
                            'status' => $itemRow['status'] ?? 'Pending',
                            'po_principal_no' => $validated['po_principal_no'] ?? null,
                            'updated_by' => Auth::id(),
                            'updated_at' => now(),
                        ]);
                        $updatedItemIds[] = $itemRow['id'];
                    } else {
                        // New item — insert as a child row under the same batch.
                        $childInquiry = Inquiry::create([
                            'parent_id' => $parentId,
                            'inquiry_code' => $inquiry->inquiry_code,
                            'inquiry_id' => $validated['enquiry_id'] ?? $inquiry->inquiry_id,
                            'item_id' => $catalogItem->id ?? null,
                            'enquiry_type' => 'PO_Principal',
                            'concerned_person' => $inquiry->concerned_person,
                            'concerned_area' => $inquiry->concerned_area,
                            'customer_id' => $inquiry->customer_id,
                            'principal_id' => $inquiry->principal_id,
                            'customer_po_no' => $inquiry->customer_po_no,
                            'customer_due_date' => $inquiry->customer_due_date,
                            'me_ref' => $inquiry->me_ref,
                            'po_amount' => $inquiry->po_amount,
                            'po_basic' => $inquiry->po_basic,
                            'cumulative' => $inquiry->cumulative,
                            'customer_pdo_document' => $inquiry->customer_pdo_document,
                            'po_principal_no' => $validated['po_principal_no'] ?? null,
                            'quantity' => $qty,
                            'price' => $itemRow['price'] ?? 0,
                            'inquiry_date' => $itemRow['date'] ?? null,
                            'status' => $itemRow['status'] ?? 'Pending',
                            'total_qty_ordered_principal' => $qty,
                            'qty_received_principal' => 0,
                            'pending_qty_principal' => $qty,
                            'released_date' => $validated['released_date'] ?? null,
                            'principal_po_document' => $document,
                            'user_id' => Auth::id(),
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                        ]);
                        $createdItemIds[] = $childInquiry->id;
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'PO to Principal updated successfully.',
                'updated_item_ids' => $updatedItemIds,
                'new_item_ids' => $createdItemIds,
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
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'po_principal_no' => 'nullable|string|max:255',
                'principal_co_no' => 'nullable|string|max:255',
                'principal_co_date' => 'nullable|date',
                'expected_delivery_date' => 'nullable|date',
                'enquiry_id' => 'nullable|string|max:255',
                'promise_date' => 'nullable|date',
                'po_date' => 'nullable|date',
                'customer_name' => 'nullable|string|max:255',
                'basic_amount' => 'nullable|numeric|min:0',
                'pf_amount' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'po_amount' => 'nullable|numeric|min:0',
                'gst_amount' => 'nullable|numeric|min:0',
                'total_with_gst' => 'nullable|numeric|min:0',
                'total_po_with_gst' => 'nullable|numeric|min:0',
                'principal_id' => 'nullable|integer|exists:users,id',
                'total_qty_ordered_principal' => 'nullable|numeric|min:0',
                'qty_received_principal' => 'nullable|numeric|min:0',
                'released_date' => 'nullable|date',
                'status' => 'nullable|string|max:100|in:Pending,Checked,Ordered Placed,Partially Placed',
                'principal_po_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
                'items' => 'nullable|array',
                'items.*.item_id' => 'nullable|integer|exists:items,id',
                'items.*.mat_code' => 'nullable|string|max:255',
                'items.*.item_name' => 'nullable|string|max:255',
                'items.*.description' => 'nullable|string|max:255',
                'items.*.part_model_no' => 'nullable|string|max:255',
                'items.*.qty' => 'nullable|numeric|min:0',
                'items.*.price' => 'nullable|numeric|min:0',
                'items.*.date' => 'nullable|date',
                'items.*.status' => 'nullable|string|max:100',
            ]);

            $totalQtyOrdered = $validated['total_qty_ordered_principal'] ?? 0;
            $qtyReceived = $validated['qty_received_principal'] ?? 0;
            $pendingQty = max(0, $totalQtyOrdered - $qtyReceived);

            $document = null;
            if ($request->hasFile('principal_po_document')) {
                $file = $request->file('principal_po_document');
                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('uploads/principal_po_documents'), $document);
            }

            $prefix = 'INQ';

            $lastInquiry = Inquiry::orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $lastNumber = 0;
            if ($lastInquiry && preg_match('/(\d+)$/', $lastInquiry->inquiry_code, $matches)) {
                $lastNumber = (int) $matches[1];
            }

            $inquiryCode = $prefix . str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

            // Resolve the typed customer name to a customer_id (same pattern
            // as the item catalog resolution below) — nullable, so a PO can
            // still be created without a customer picked.
            $customerId = null;
            if (!empty($validated['customer_name'])) {
                $resolvedCustomer = User::where('name', $validated['customer_name'])->first();
                if ($resolvedCustomer) {
                    $customerId = $resolvedCustomer->id;
                }
            }

            $basicAmount = $validated['basic_amount'] ?? 0;
            $pfAmount = $validated['pf_amount'] ?? 0;
            $freightAmount = $validated['freight_amount'] ?? 0;
            $poAmount = $validated['po_amount'] ?? 0;
            $gstAmount = $validated['gst_amount'] ?? 0;
            $totalWithGst = isset($validated['total_with_gst']) && (float) $validated['total_with_gst'] > 0
                ? (float) $validated['total_with_gst']
                : (isset($validated['total_po_with_gst']) && (float) $validated['total_po_with_gst'] > 0
                    ? (float) $validated['total_po_with_gst']
                    : round($poAmount + ($poAmount * $gstAmount / 100), 2));

            // Resolve/create the catalog Item for every submitted row up front
            // (same pattern as convertToprincipalqutation()), and drop any row
            // with no qty. Item catalog fields (mat_code/item_name/description/
            // part_model_no) live on the `items` table, NOT on the Inquiry row.
            $preparedItems = [];
            foreach (($validated['items'] ?? []) as $itemRow) {
                $qty = (float) ($itemRow['qty'] ?? 0);
                if ($qty <= 0)
                    continue;

                $catalogItem = null;

                if (!empty($itemRow['item_id'])) {
                    $catalogItem = Item::find($itemRow['item_id']);
                }

                if (!$catalogItem && !empty($itemRow['mat_code'])) {
                    $catalogItem = Item::firstOrCreate(
                        ['mat_code' => $itemRow['mat_code']],
                        [
                            'name' => $itemRow['item_name'] ?: $itemRow['mat_code'],
                            'part_model_no' => $itemRow['part_model_no'] ?? '',
                            'description' => $itemRow['description'] ?? '',
                        ]
                    );
                }

                if ($catalogItem) {
                    $catalogItem->fill([
                        'mat_code' => $itemRow['mat_code'] ?? $catalogItem->mat_code,
                        'name' => !empty($itemRow['item_name']) ? $itemRow['item_name'] : $catalogItem->name,
                        'description' => $itemRow['description'] ?? $catalogItem->description,
                        'part_model_no' => $itemRow['part_model_no'] ?? $catalogItem->part_model_no,
                    ])->save();
                }

                $preparedItems[] = [
                    'item_id' => $catalogItem->id ?? null,
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

            // The FIRST item (if any) merges straight into the root row instead
            // of getting a separate child row — same idea as
            // convertTopoprincipal()'s "first conversion": the root record IS
            // the first item, not a separate wrapper sitting next to it. That
            // keeps enquiry_type => 'PO_Principal' everywhere, with no extra
            // "container" row showing up as a phantom item.
            $firstItem = array_shift($preparedItems);

            $inquiry = Inquiry::create([
                'parent_id' => 0,
                'inquiry_code' => $inquiryCode,
                'inquiry_id' => $validated['enquiry_id'] ?? $inquiryCode,
                'enquiry_type' => 'PO_Principal',
                'item_id' => $firstItem['item_id'] ?? null,
                'principal_id' => $validated['principal_id'] ?? null,
                'po_principal_no' => $validated['po_principal_no'] ?? null,
                'principal_co_no' => $validated['principal_co_no'] ?? null,
                'principal_co_date' => $validated['principal_co_date'] ?? null,
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'promise_date' => $validated['promise_date'] ?? null,
                'po_date' => $validated['po_date'] ?? null,
                'customer_id' => $customerId,
                'basic_amount' => $basicAmount,
                'pf_amount' => $pfAmount,
                'freight_amount' => $freightAmount,
                'po_amount_principal' => $poAmount,
                'gst_amount' => $gstAmount,
                'total_with_gst' => $totalWithGst,
                'quantity' => $firstItem['qty'] ?? null,
                'price' => $firstItem['price'] ?? 0,
                'inquiry_date' => $firstItem['date'] ?? null,
                'total_qty_ordered_principal' => $firstItem ? $firstItem['qty'] : $totalQtyOrdered,
                'qty_received_principal' => $qtyReceived,
                'pending_qty_principal' => $firstItem ? $firstItem['qty'] : $pendingQty,
                'released_date' => $validated['released_date'] ?? null,
                'status' => $firstItem['status'] ?? ($validated['status'] ?? 'Pending'),
                'principal_po_document' => $document,
                'user_id' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $inquiry->update(['parent_id' => $inquiry->id]);

            $createdItemIds = [$inquiry->id];

            // Every remaining item (2nd onward) gets its own child row.
            foreach ($preparedItems as $item) {
                $childInquiry = Inquiry::create([
                    'parent_id' => $inquiry->id,
                    'inquiry_code' => $inquiry->inquiry_code,
                    'inquiry_id' => $inquiry->inquiry_id,
                    'item_id' => $item['item_id'],
                    'enquiry_type' => 'PO_Principal',
                    'principal_id' => $validated['principal_id'] ?? null,
                    'po_principal_no' => $validated['po_principal_no'] ?? null,
                    'principal_co_no' => $validated['principal_co_no'] ?? null,
                    'principal_co_date' => $validated['principal_co_date'] ?? null,
                    'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                    'promise_date' => $validated['promise_date'] ?? null,
                    'po_date' => $validated['po_date'] ?? null,
                    'customer_id' => $customerId,
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'inquiry_date' => $item['date'],
                    'status' => $item['status'],
                    'total_qty_ordered_principal' => $item['qty'],
                    'qty_received_principal' => 0,
                    'pending_qty_principal' => $item['qty'],
                    'user_id' => Auth::id(),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                $createdItemIds[] = $childInquiry->id;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'PO to Principal created successfully.',
                'data' => $inquiry->fresh(),
                'item_ids' => $createdItemIds,
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}