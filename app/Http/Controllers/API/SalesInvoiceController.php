<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SalesInvoiceController extends Controller
{
    public function convertTosalesinvoice(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $firstInquiry = Inquiry::find($id);

            if (!$firstInquiry) {
                return response()->json(['status' => false, 'message' => 'Inquiry not found.'], 404);
            }

            if ($firstInquiry->user_id != Auth::id() && Auth::user()->role != 'admin') {
                return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
            }

            $validated = $request->validate([
                'item_ids'                   => 'nullable|array',
                'item_ids.*'                 => 'nullable|integer',
                'item_quantities'             => 'nullable|array',
                'item_quantities.*.id'        => 'nullable|integer',
                'item_quantities.*.quantity'  => 'nullable|numeric',
                'item_quantities.*.price'     => 'nullable|numeric|min:0',
                'sales_invoice_no'            => 'nullable|string|max:255',
                'sales_invoice_date'          => 'nullable|date',
                'principal_id'                => 'nullable|integer|exists:users,id',
                'sale_basic'                  => 'nullable|numeric|min:0',
                'sale_basic_cumulative'       => 'nullable|numeric|min:0',
                'sale_basic_gst'              => 'nullable|numeric|min:0',
                'sale_cumulative'             => 'nullable|numeric|min:0',
                'cumulative'                  => 'nullable|numeric|min:0',
                'gst_percent'                 => 'nullable|numeric|min:0',
                'status'                      => 'nullable|string|max:100',
            ]);

            // Make sure every submitted item_id actually belongs to this inquiry group.
            $targetItemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$firstInquiry->id];
            $validCount = Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
                ->whereIn('id', $targetItemIds)
                ->count();

            if ($validCount !== count($targetItemIds)) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'One or more selected items do not belong to this inquiry group.',
                ], 422);
            }

            $qtyOrderedTotal = (int) $firstInquiry->customer_qty_order;

            // Total billed qty across all selected items, used for the
            // pending-after-bill calculation on each row.
            $totalQtyBilled = array_sum(array_column($validated['item_quantities'], 'quantity'));
            $pendingAfterBill = max(0, $qtyOrderedTotal - $totalQtyBilled);

            $principalId = $validated['principal_id'] ?? $request->principal_id ?? $firstInquiry->principal_id;
            $saleBasic = (float) ($validated['sale_basic'] ?? 0);
            $saleBasicGst = (float) ($validated['sale_basic_gst'] ?? $saleBasic);

            $rawBasicCumul = $validated['sale_basic_cumulative'] ?? null;
            $reqBasicCumul = ($rawBasicCumul !== null && $rawBasicCumul !== '') ? (float) $rawBasicCumul : null;

            $rawGstCumul = $validated['cumulative'] ?? ($validated['sale_cumulative'] ?? null);
            $reqGstCumul = ($rawGstCumul !== null && $rawGstCumul !== '') ? (float) $rawGstCumul : null;

            $excludeId = (int) ($firstInquiry->parent_id ?: $firstInquiry->id);
            $basicCumulValue = $this->resolveSaleBasicCumulative($reqBasicCumul, $saleBasic, $excludeId);
            $gstCumulValue = $this->resolveSaleGstCumulative($reqGstCumul, $saleBasicGst, $excludeId);

            $affectedTotal = 0;

            foreach ($validated['item_quantities'] as $itemData) {
                $itemId = $itemData['id'];
                $qty = $itemData['quantity'];
                $price = $itemData['price'];
                $itemTotal = $price;

                $affected = Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
                    ->where('id', $itemId)
                    ->update([
                        'enquiry_type'           => 'Sales_Invoice',
                        'sales_invoice_no'       => $validated['sales_invoice_no'],
                        'sales_invoice_date'     => $validated['sales_invoice_date'],
                        'principal_id'           => $principalId,
                        'sale_basic'             => $saleBasic,
                        'sale_basic_cumulative'  => $basicCumulValue,
                        'sale_basic_gst'         => $saleBasicGst,
                        'sale_cumulative'        => $gstCumulValue,
                        'cumulative'             => $gstCumulValue,
                        'gst_percent'            => $validated['gst_percent'] ?? 0,
                        // per-item fields (real columns)
                        'price'                  => $price,
                        // summary fields mirrored per row for list aggregation
                        'qty_billed'             => $qty,
                        'total_selling_value'    => $itemTotal,
                        'pending_qty_after_bill' => $pendingAfterBill,
                        'status'                 => $validated['status'],
                        'updated_by'             => Auth::id(),
                        'updated_at'             => now(),
                    ]);

                $affectedTotal += $affected;
            }

            if ($affectedTotal === 0) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'No matching items found for this inquiry group. Nothing was updated.',
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => "Sales Invoice created successfully ({$affectedTotal} item(s) updated).",
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Sales Invoice Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sellinvoiceList(Request $request)
    {
        try {

            $stages = ['Sales_Invoice'];

            $query = Inquiry::with([
                'customer:id,name',
                'principal:id,name',
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
                    'item:id,name,mat_code,part_model_no,description',
                    'principal:id,name'
                ])
                    ->where('parent_id', $enquiry->id)
                    ->whereIn('enquiry_type', $stages)
                    ->get();

                if ($items->isEmpty()) {
                    continue;
                }

                $batches = $items->groupBy(function ($row) {
                    return $row->sales_invoice_no;
                });

                foreach ($batches as $batchItems) {

                    $first = $batchItems->first();

                    // Batch-level filters — sales_invoice_no / status only
                    // ever live on the converted rows, not the anchor, so
                    // they're checked here rather than in the base query.
                    if ($request->filled('search')) {
                        $search = strtolower($request->search);
                        $haystack = strtolower(
                            $first->enquiry_type . ' ' .
                            $first->sales_invoice_no . ' ' .
                            $first->status
                        );
                        if (!str_contains($haystack, $search)) {
                            continue;
                        }
                    }

                    if ($request->filled('status') && $first->status != $request->status) {
                        continue;
                    }

                    // Aggregate across all items in this batch, since each
                    // item can carry its own quantity and price.
                    $totalQtyBilled = $batchItems->sum('quantity');
                    $totalQtyBilled = $batchItems->sum(fn($r) => (float) ($r->qty_billed ?? $r->quantity));
                    $totalSellingValue = $batchItems->sum('price');

                    $principalId = $first->principal_id ?? $enquiry->principal_id;
                    $principalName = optional($first->principal)->name ?? optional($enquiry->principal)->name ?? '';

                    $data[] = [
                        'id'                     => $first->id,
                        'parent_id'              => $enquiry->id,
                        'inquiry_id'             => $enquiry->inquiry_id,
                        'inquiry_code'           => $enquiry->inquiry_code,
                        'enquiry_type'           => $first->enquiry_type,
                        'customer_id'            => $enquiry->customer_id,
                        'customer_name'          => optional($enquiry->customer)->name,
                        'principal_id'           => $principalId,
                        'principal_name'         => $principalName,
                        'purchase_invoice_no'    => $first->purchase_invoice_no,
                        'sales_invoice_no'       => $first->sales_invoice_no,
                        'sales_invoice_date'     => $first->sales_invoice_date,
                        'gst_percent'            => $first->gst_percent,
                        'sale_basic'             => $first->sale_basic,
                        'sale_basic_cumulative'  => $first->sale_basic_cumulative ?? $first->sale_cumulative ?? $first->cumulative,
                        'sale_basic_cumulative'  => $first->sale_basic_cumulative ?? $first->sale_basic,
                        'sale_basic_gst'         => $first->sale_basic_gst,
                        'sale_cumulative'        => $first->sale_cumulative ?? $first->cumulative,
                        'cumulative'             => $first->sale_cumulative ?? $first->cumulative,
                        'cumulative'             => $first->cumulative ?? $first->sale_cumulative,
                        'qty_billed'             => $totalQtyBilled,
                        'total_selling_value'    => $totalSellingValue,
                        'pending_qty_after_bill' => $first->pending_qty_after_bill,
                        'status'                 => $first->status,
                        'due_date_basis'         => $first->due_date_basis,

                        // Stage 10: Customer Invoice Submission / Portal Upload fields
                        'portal_submission_required'   => $first->portal_submission_required ?? 'Yes',
                        'portal_submission_method'     => $first->portal_submission_method ?? 'Vendor Portal',
                        'portal_submission_status'     => $first->portal_submission_status ?? 'Pending',
                        'portal_submission_date'       => $first->portal_submission_date ? Carbon::parse($first->portal_submission_date)->format('Y-m-d') : null,
                        'portal_ack_ref'               => $first->portal_ack_ref,
                        'portal_rejection_reason'      => $first->portal_rejection_reason,
                        'portal_resubmission_date'     => $first->portal_resubmission_date ? Carbon::parse($first->portal_resubmission_date)->format('Y-m-d') : null,
                        'portal_resubmission_remarks'  => $first->portal_resubmission_remarks,
                        'portal_supporting_docs'       => $first->portal_supporting_docs ?? 'Pending',
                        'portal_next_followup_date'    => $first->portal_next_followup_date ? Carbon::parse($first->portal_next_followup_date)->format('Y-m-d') : null,
                        'portal_responsible_person'    => $first->portal_responsible_person,

                        'items' => $batchItems->map(function ($row) {
                            $itemQty = $row->qty_billed ?? $row->quantity;
                            return [
                                'id'            => $row->id,
                                'item_id'       => $row->item_id,
                                'material_code' => optional($row->item)->mat_code,
                                'item_name'     => optional($row->item)->name,
                                'description'   => optional($row->item)->description,
                                'part_model_no' => optional($row->item)->part_model_no,
                                'quantity'      => $row->quantity,
                                'qty_billed'    => $itemQty,
                                'price'         => $row->price,
                                'status'        => $row->status,
                                'date'          => optional($row->created_at)->format('Y-m-d'),
                                'isConverted'   => $row->enquiry_type !== 'Sales_Invoice',
                            ];
                        })->values(),
                    ];
                }
            }

            return response()->json(['status' => true, 'message' => 'Sales Invoice List', 'data' => $data], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
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

        // Sorting — 'sales_invoice_status' isn't a real column; the field
        // is just 'status' everywhere else in this controller.
        $allowedSorts = ['id', 'sales_invoice_date', 'status', 'created_at'];

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
                $end   = $now->copy()->endOfWeek();
                break;

            case 'monthly':
                $start = $now->copy()->startOfMonth();
                $end   = $now->copy()->endOfMonth();
                break;

            case 'quarterly':
                $start = $now->copy()->firstOfQuarter();
                $end   = $now->copy()->lastOfQuarter();
                break;

            case 'yearly':
                $start = $now->copy()->startOfYear();
                $end   = $now->copy()->endOfYear();
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
                'item_ids'                   => 'nullable|array',
                'item_ids.*'                 => 'nullable|integer',
                'item_quantities'             => 'nullable|array',
                'item_quantities.*.id'        => 'nullable|integer',
                'item_quantities.*.quantity'  => 'nullable|numeric',
                'item_quantities.*.price'     => 'nullable|numeric|min:0',
                'sales_invoice_no'            => 'nullable|string|max:255',
                'sales_invoice_date'          => 'nullable|date',
                'principal_id'                => 'nullable|integer|exists:users,id',
                'sale_basic'                  => 'nullable|numeric|min:0',
                'sale_basic_cumulative'       => 'nullable|numeric|min:0',
                'sale_basic_gst'              => 'nullable|numeric|min:0',
                'sale_cumulative'             => 'nullable|numeric|min:0',
                'cumulative'                  => 'nullable|numeric|min:0',
                'gst_percent'                 => 'nullable|numeric|min:0',
                'status'                      => 'nullable|string|max:100',
            ]);

            $inquiry = Inquiry::findOrFail($id);
            $parentId = $inquiry->parent_id;

            // Confirm every submitted item actually belongs to this group before writing anything.
            $validCount = Inquiry::where('parent_id', $parentId)
                ->whereIn('id', $validated['item_ids'])
                ->count();

            if ($validCount !== count($validated['item_ids'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected items do not belong to this inquiry group.',
                ], 422);
            }

            $qtyOrderedTotal = (int) $inquiry->customer_qty_order;
            $totalQtyBilled = array_sum(array_column($validated['item_quantities'], 'quantity'));
            $pendingAfterBill = max(0, $qtyOrderedTotal - $totalQtyBilled);

            $principalId = $validated['principal_id'] ?? $request->principal_id ?? $inquiry->principal_id;
            $saleBasic = (float) ($validated['sale_basic'] ?? $inquiry->sale_basic ?? 0);
            $saleBasicGst = (float) ($validated['sale_basic_gst'] ?? $inquiry->sale_basic_gst ?? $saleBasic);

            $rawBasicCumul = $validated['sale_basic_cumulative'] ?? null;
            $reqBasicCumul = ($rawBasicCumul !== null && $rawBasicCumul !== '') ? (float) $rawBasicCumul : null;

            $rawGstCumul = $validated['cumulative'] ?? ($validated['sale_cumulative'] ?? null);
            $reqGstCumul = ($rawGstCumul !== null && $rawGstCumul !== '') ? (float) $rawGstCumul : null;

            $excludeId = (int) ($parentId ?: $id);
            $basicCumulValue = $this->resolveSaleBasicCumulative($reqBasicCumul, $saleBasic, $excludeId);
            $gstCumulValue = $this->resolveSaleGstCumulative($reqGstCumul, $saleBasicGst, $excludeId);

            $affectedTotal = 0;

            foreach ($validated['item_quantities'] as $itemData) {
                $itemId = $itemData['id'];
                $qty = $itemData['quantity'];
                $price = $itemData['price'];
                $itemTotal = $price;

                $affected = Inquiry::where('parent_id', $parentId)
                    ->where('id', $itemId)
                    ->update([
                        'sales_invoice_no'       => $validated['sales_invoice_no'],
                        'sales_invoice_date'     => $validated['sales_invoice_date'],
                        'principal_id'           => $principalId,
                        'sale_basic'             => $saleBasic,
                        'sale_basic_cumulative'  => $basicCumulValue,
                        'sale_basic_gst'         => $saleBasicGst,
                        'sale_cumulative'        => $gstCumulValue,
                        'cumulative'             => $gstCumulValue,
                        'gst_percent'            => $validated['gst_percent'] ?? 0,
                        'price'                  => $price,
                        'qty_billed'             => $qty,
                        'total_selling_value'    => $itemTotal,
                        'pending_qty_after_bill' => $pendingAfterBill,
                        'status'                 => $validated['status'],
                        'updated_by'             => Auth::id(),
                        'updated_at'             => now(),
                    ]);

                $affectedTotal += $affected;
            }

            if ($affectedTotal === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No matching items found. Nothing was updated.',
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Sales Invoice updated successfully ({$affectedTotal} item(s)).",
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve the cumulative value for Sale Basic.
     * When first inserted, sale basic is cumulative.
     * Subsequent inserts: previous cumulative + recent sale basic.
     */
    private function resolveSaleBasicCumulative(?float $requestedCumulative, float $saleBasic, ?int $excludeParentId = null): float
    {
        if ($requestedCumulative !== null && $requestedCumulative > 0) {
            return $requestedCumulative;
        }

        $query = Inquiry::whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
            ->whereNotNull('sale_basic_cumulative')
            ->where('sale_basic_cumulative', '>', 0);

        if ($excludeParentId) {
            $query->where('parent_id', '!=', $excludeParentId)->where('id', '!=', $excludeParentId);
        }

        $prev = $query->orderByDesc('id')->value('sale_basic_cumulative');

        if ($prev !== null && (float) $prev > 0) {
            return round((float) $prev + $saleBasic, 2);
        }

        return $saleBasic;
    }

    /**
     * Stage 10: Update Customer Invoice Submission / Portal Upload details
     */
    public function updatePortalSubmission(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $inquiry = Inquiry::findOrFail($id);

            $validated = $request->validate([
                'portal_submission_required'   => 'nullable|string|in:Yes,No',
                'portal_submission_method'     => 'nullable|string|max:50',
                'portal_submission_status'     => 'nullable|string|in:Pending,Submitted,Accepted,Rejected,Not Required,Pending Upload',
                'portal_submission_date'       => 'nullable|date',
                'portal_ack_ref'               => 'nullable|string|max:255',
                'portal_rejection_reason'      => 'nullable|string',
                'portal_resubmission_date'     => 'nullable|date',
                'portal_resubmission_remarks'  => 'nullable|string',
                'portal_supporting_docs'       => 'nullable|string|max:50',
                'portal_next_followup_date'    => 'nullable|date',
                'portal_responsible_person'    => 'nullable|string|max:255',
            ]);

            // Update all item rows matching this sales invoice batch
            $matchQuery = Inquiry::where('enquiry_type', 'Sales_Invoice');
            if ($inquiry->sales_invoice_no) {
                $matchQuery->where('sales_invoice_no', $inquiry->sales_invoice_no);
            } else {
                $matchQuery->where('id', $inquiry->id);
            }

            $updateData = [
                'portal_submission_required'   => $validated['portal_submission_required'] ?? $inquiry->portal_submission_required ?? 'Yes',
                'portal_submission_method'     => $validated['portal_submission_method'] ?? $inquiry->portal_submission_method ?? 'Vendor Portal',
                'portal_submission_status'     => $validated['portal_submission_status'],
                'portal_submission_date'       => $validated['portal_submission_date'] ?? null,
                'portal_ack_ref'               => $validated['portal_ack_ref'] ?? null,
                'portal_rejection_reason'      => $validated['portal_rejection_reason'] ?? null,
                'portal_resubmission_date'     => $validated['portal_resubmission_date'] ?? null,
                'portal_resubmission_remarks'  => $validated['portal_resubmission_remarks'] ?? null,
                'portal_supporting_docs'       => $validated['portal_supporting_docs'] ?? 'Pending',
                'portal_next_followup_date'    => $validated['portal_next_followup_date'] ?? null,
                'portal_responsible_person'    => $validated['portal_responsible_person'] ?? null,
                'updated_by'                   => Auth::id(),
                'updated_at'                   => now(),
            ];

            // If status is Accepted, update due_date_basis on the invoice to 'Acceptance Date'
            if ($validated['portal_submission_status'] === 'Accepted') {
                $updateData['due_date_basis'] = 'Acceptance Date';
            }

            $affected = $matchQuery->update($updateData);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => "Portal submission updated successfully ({$affected} item(s) updated).",
                'data'    => $updateData,
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Portal submission update error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve the cumulative value for Sale Basic + GST.
     * When first inserted, sale basic + gst is cumulative.
     * Subsequent inserts: previous cumulative + recent sale basic + gst.
     */
    private function resolveSaleGstCumulative(?float $requestedCumulative, float $saleBasicGst, ?int $excludeParentId = null): float
    {
        if ($requestedCumulative !== null && $requestedCumulative > 0) {
            return $requestedCumulative;
        }

        $query = Inquiry::whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
            ->where(function ($q) {
                $q->whereNotNull('cumulative')->where('cumulative', '>', 0)
                  ->orWhere(function ($sq) {
                      $sq->whereNotNull('sale_cumulative')->where('sale_cumulative', '>', 0);
                  });
            });

        if ($excludeParentId) {
            $query->where('parent_id', '!=', $excludeParentId)->where('id', '!=', $excludeParentId);
        }

        $prev = $query->orderByDesc('id')->value('cumulative')
            ?? $query->orderByDesc('id')->value('sale_cumulative');

        if ($prev !== null && (float) $prev > 0) {
            return round((float) $prev + $saleBasicGst, 2);
        }

        return $saleBasicGst;
    }
}