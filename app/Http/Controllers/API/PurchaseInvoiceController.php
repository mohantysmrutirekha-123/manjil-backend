<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Item;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceController extends Controller
{
    public function convertTopurchaseinvoice(Request $request, $id)
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
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'nullable|integer',
                'item_quantities' => 'nullable|array',
                'item_quantities.*.id' => 'nullable|integer',
                'item_quantities.*.quantity' => 'nullable|numeric',
                'item_quantities.*.price' => 'nullable|numeric|min:0',
                'purchase_invoice_no' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'basic_amount' => 'nullable|numeric|min:0',
                'pf_amount' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'invoice_amount' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'basic_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'pf_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'freight_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'invoice_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'total_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'gst_tax_amount' => 'nullable|numeric|min:0',
                'payment_due_date' => 'nullable|date',
                'purchase_payment_status' => 'nullable|in:Pending,Partial,Paid,30day credit',
            ]);

            $targetItemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$firstInquiry->id];
            $validCount = Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
                ->whereIn('id', $targetItemIds)
                ->count();

            if ($validCount !== count($targetItemIds)) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'One or more selected items do not belong to this inquiry group.',
                ], 422);
            }

            $affectedTotal = 0;

            $basicAmount = $validated['basic_amount_purchase_invoice'] ?? $validated['basic_amount'] ?? null;
            $pfAmount = $validated['pf_amount_purchase_invoice'] ?? $validated['pf_amount'] ?? null;
            $freightAmount = $validated['freight_amount_purchase_invoice'] ?? $validated['freight_amount'] ?? null;
            $invoiceAmount = $validated['invoice_amount_purchase_invoice'] ?? $validated['invoice_amount'] ?? null;
            if (($invoiceAmount === null || $invoiceAmount === '' || (float)$invoiceAmount == 0) && $basicAmount !== null) {
                $invoiceAmount = round((float)$basicAmount + (float)($pfAmount ?? 0) + (float)($freightAmount ?? 0), 2);
            }
            $gstTaxAmount = $validated['gst_tax_amount'] ?? 0;
            $totalAmount = $validated['total_amount_purchase_invoice'] ?? $validated['total_amount'] ?? null;
            if (($totalAmount === null || $totalAmount === '' || (float)$totalAmount == 0) && $invoiceAmount !== null) {
                $totalAmount = round((float)$invoiceAmount + ((float)$invoiceAmount * (float)$gstTaxAmount / 100), 2);
            }

            $createdRows = [];
            foreach (($validated['item_quantities'] ?? []) as $itemData) {
                $itemId = $itemData['id'];
                $batchQty = (float) $itemData['quantity'];
                $price = (float) $itemData['price'];

                if ($batchQty <= 0) {
                    continue;
                }

                $existingItem = Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
                    ->where('id', $itemId)
                    ->first();

                if (!$existingItem) {
                    continue;
                }

                $totalOrdered = (float) (
                    $existingItem->total_qty_ordered_principal ?:
                    $existingItem->total_qty_ordered_principa ?:
                    $existingItem->quantity ?:
                    0
                );

                $alreadyInvoiced = (float) ($existingItem->qty_invoiced ?? 0);

                $remainingBefore = max(0, $totalOrdered - $alreadyInvoiced);
                if ($remainingBefore <= 0 && $alreadyInvoiced == 0 && $totalOrdered > 0) {
                    $remainingBefore = $totalOrdered;
                }

                $newCumulativeInvoiced = min($totalOrdered > 0 ? $totalOrdered : ($alreadyInvoiced + $batchQty), $alreadyInvoiced + $batchQty);
                $newRemaining = max(0, $remainingBefore - $batchQty);

                // Update the original PO to Principal item to maintain unconverted balance and cumulative invoiced qty
                $existingItem->update([
                    'qty_invoiced' => $newCumulativeInvoiced,
                    'pending_qty_principal' => $newRemaining,
                    'status' => $existingItem->status ?: 'Ordered Placed',
                    'updated_by' => Auth::id(),
                    'updated_at' => $existingItem->updated_at,
                ]);

                // Create a separate Purchase Invoice row for this specific invoice batch
                $invoiceRow = Inquiry::create([
                    'inquiry_code' => $existingItem->inquiry_code,
                    'parent_id' => $existingItem->parent_id ?: $existingItem->id,
                    'inquiry_id' => $existingItem->inquiry_id,
                    'item_id' => $existingItem->item_id,
                    'customer_id' => $existingItem->customer_id,
                    'principal_id' => $existingItem->principal_id,
                    'concerned_person' => $existingItem->concerned_person,
                    'concerned_area' => $existingItem->concerned_area,
                    'po_principal_no' => $existingItem->po_principal_no,
                    'purchase_invoice_no' => $validated['purchase_invoice_no'] ?? null,
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'payment_due_date' => $validated['payment_due_date'] ?? null,
                    'purchase_payment_status' => $validated['purchase_payment_status'] ?? 'Pending',
                    'enquiry_type' => 'Purchase_Invoice',
                    'quantity' => $batchQty,
                    'qty_invoiced' => $batchQty,
                    'total_qty_ordered_principal' => null,
                    'pending_qty_principal' => null,
                    'unit_purchase_price' => $price,
                    'price' => $price,
                    'basic_amount' => $basicAmount,
                    'pf_amount' => $pfAmount,
                    'freight_amount' => $freightAmount,
                    'invoice_amount' => $invoiceAmount,
                    'total_amount' => $totalAmount,
                    'basic_amount_purchase_invoice' => $basicAmount,
                    'pf_amount_purchase_invoice' => $pfAmount,
                    'freight_amount_purchase_invoice' => $freightAmount,
                    'invoice_amount_purchase_invoice' => $invoiceAmount,
                    'total_amount_purchase_invoice' => $totalAmount,
                    'gst_tax_amount' => $validated['gst_tax_amount'] ?? 0,
                    'total_purchase_value' => $totalAmount ?? $price,
                    'status' => 'Pending',
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                $createdRows[] = $invoiceRow->id;
                $affectedTotal++;
            }

            if ($affectedTotal === 0) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'No matching items found for this inquiry group. Nothing was updated.',
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => "Purchase Invoice created successfully ({$affectedTotal} item(s) updated).",
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Purchase Invoice Error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function purchaseinvoiceList(Request $request)
    {

        try {

            $stages = ['Purchase_Invoice', 'Sales_Invoice'];

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

                $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
                    ->where('parent_id', $enquiry->id)
                    ->whereIn('enquiry_type', $stages)
                    ->get();

                if ($items->isEmpty()) {
                    continue;
                }

                // Group by purchase_invoice_no so each separate conversion gets its own row.
                $batches = $items->groupBy(function ($row) {
                    return $row->purchase_invoice_no;
                });

                foreach ($batches as $batchItems) {

                    $sourceRow = $batchItems->first();

                    // Batch-level filters — purchase_invoice_no / status only
                    // ever live on the converted rows, not the anchor, so they
                    // have to be checked here rather than in the base query.
                    if ($request->filled('search')) {
                        $search = strtolower($request->search);
                        $haystack = strtolower(
                            $sourceRow->enquiry_type . ' ' .
                            $sourceRow->purchase_invoice_no . ' ' .
                            $sourceRow->purchase_payment_status
                        );
                        if (!str_contains($haystack, $search)) {
                            continue;
                        }
                    }

                    if ($request->filled('purchase_payment_status') && $sourceRow->purchase_payment_status != $request->purchase_payment_status) {
                        continue;
                    }

                    // Aggregate totals across all items in this batch, so the
                    // summary reflects the whole invoice rather than just the
                    // first item's own qty/price.
                    $totalQtyInvoiced = $batchItems->sum('quantity');
                    $totalQtyInvoiced = $batchItems->sum(fn($r) => (float) ($r->qty_invoiced ?? $r->quantity));
                    $totalPurchaseValue = $batchItems->sum('price');

                    $data[] = [
                        'id' => $sourceRow->id,
                        'parent_id' => $enquiry->id,
                        'inquiry_id' => $enquiry->inquiry_id,
                        'inquiry_code' => $enquiry->inquiry_code,
                        'enquiry_type' => $sourceRow->enquiry_type,
                        'customer_id' => $enquiry->customer_id,
                        'customer_name' => optional($enquiry->customer)->name,
                        'principal_id' => $sourceRow->principal_id,
                        'principal_name' => optional($sourceRow->principal)->name ?? optional($enquiry->principal)->name ?? '',
                        'po_principal_no' => $sourceRow->po_principal_no,
                        'purchase_invoice_no' => $sourceRow->purchase_invoice_no,
                        'invoice_date' => $sourceRow->invoice_date,
                        'basic_amount' => $sourceRow->basic_amount_purchase_invoice ?? $sourceRow->basic_amount ?? '',
                        'pf_amount' => $sourceRow->pf_amount_purchase_invoice ?? $sourceRow->pf_amount ?? '',
                        'freight_amount' => $sourceRow->freight_amount_purchase_invoice ?? $sourceRow->freight_amount ?? '',
                        'invoice_amount' => $sourceRow->invoice_amount_purchase_invoice ?? $sourceRow->invoice_amount ?? '',
                        'total_amount' => $sourceRow->total_amount_purchase_invoice ?? $sourceRow->total_amount ?? $totalPurchaseValue,
                        'basic_amount_purchase_invoice' => $sourceRow->basic_amount_purchase_invoice ?? $sourceRow->basic_amount ?? '',
                        'pf_amount_purchase_invoice' => $sourceRow->pf_amount_purchase_invoice ?? $sourceRow->pf_amount ?? '',
                        'freight_amount_purchase_invoice' => $sourceRow->freight_amount_purchase_invoice ?? $sourceRow->freight_amount ?? '',
                        'invoice_amount_purchase_invoice' => $sourceRow->invoice_amount_purchase_invoice ?? $sourceRow->invoice_amount ?? '',
                        'total_amount_purchase_invoice' => $sourceRow->total_amount_purchase_invoice ?? $sourceRow->total_amount ?? $totalPurchaseValue,
                        'unit_purchase_price' => $sourceRow->unit_purchase_price,
                        'qty_invoiced' => $totalQtyInvoiced,
                        'gst_tax_amount' => $sourceRow->gst_tax_amount,
                        'total_purchase_value' => $totalPurchaseValue,
                        'payment_due_date' => $sourceRow->payment_due_date,
                        'pay_later_payment_date' => $sourceRow->pay_later_payment_date,
                        'pay_later_status' => $sourceRow->pay_later_status,
                        'purchase_payment_status' => $sourceRow->purchase_payment_status,
                        'status' => $sourceRow->status,

                        'items' => $batchItems->map(function ($row) {
                            $itemQty = (float) ($row->qty_invoiced ?? $row->quantity ?? 0);
                            $qtyBilled = (float) ($row->qty_billed ?? 0);
                            $isFully = $itemQty > 0 ? ($qtyBilled >= $itemQty) : ($row->enquiry_type !== 'Purchase_Invoice');
                            $isPartial = $itemQty > 0 && $qtyBilled > 0 && $qtyBilled < $itemQty;
                            return [
                                'id' => $row->id,
                                'item_id' => $row->item_id,
                                'material_code' => optional($row->item)->mat_code,
                                'item_name' => optional($row->item)->name,
                                'description' => optional($row->item)->description,
                                'part_model_no' => optional($row->item)->part_model_no,
                                'quantity' => $itemQty,
                                'qty_invoiced' => $itemQty,
                                'qty_billed' => $row->qty_billed,
                                'sales_invoice_no' => $row->sales_invoice_no,
                                'customer_qty_order' => $row->customer_qty_order,
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

            return response()->json(['status' => true, 'message' => 'Purchase Invoice List', 'data' => $data], 200);

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

        // Sorting
        $allowedSorts = ['id', 'invoice_date', 'payment_due_date', 'purchase_payment_status', 'created_at'];

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
                'purchase_invoice_no' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'basic_amount' => 'nullable|numeric|min:0',
                'pf_amount' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'invoice_amount' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'basic_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'pf_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'freight_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'invoice_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'total_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'principal_id' => 'nullable|integer|exists:users,id',
                'enquiry_ref' => 'nullable|string|max:255',
                'gst_tax_amount' => 'nullable|numeric|min:0',
                'payment_due_date' => 'nullable|date',
                'purchase_payment_status' => 'nullable|in:Pending,Partial,Paid,30day credit',
                'status' => 'nullable|string|max:100',
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'nullable|integer',
                'items' => 'nullable|array',
                'items.*.id' => 'nullable|integer',
                'items.*.item_id' => 'nullable|integer',
                'items.*.mat_code' => 'nullable|string|max:255',
                'items.*.item_name' => 'nullable|string|max:255',
                'items.*.description' => 'nullable|string|max:255',
                'items.*.part_model_no' => 'nullable|string|max:255',
                'items.*.qty' => 'nullable|numeric|min:0',
                'items.*.quantity' => 'nullable|numeric|min:0',
                'items.*.price' => 'nullable|numeric|min:0',
                'items.*.date' => 'nullable|date',
                'items.*.status' => 'nullable|string|max:100',
                'item_quantities' => 'nullable|array',
                'item_quantities.*.id' => 'nullable|integer',
                'item_quantities.*.quantity' => 'nullable|numeric|min:0',
                'item_quantities.*.price' => 'nullable|numeric|min:0',
            ]);

            $inquiry = Inquiry::findOrFail($id);
            $parentId = $inquiry->parent_id ?: $inquiry->id;
            $gstTaxAmount = $validated['gst_tax_amount'] ?? 0;

            $basicAmount = $validated['basic_amount_purchase_invoice'] ?? $validated['basic_amount'] ?? $inquiry->basic_amount;
            $pfAmount = $validated['pf_amount_purchase_invoice'] ?? $validated['pf_amount'] ?? $inquiry->pf_amount;
            $freightAmount = $validated['freight_amount_purchase_invoice'] ?? $validated['freight_amount'] ?? $inquiry->freight_amount;
            $invoiceAmount = $validated['invoice_amount_purchase_invoice'] ?? $validated['invoice_amount'] ?? $inquiry->invoice_amount;
            if (($invoiceAmount === null || $invoiceAmount === '' || (float)$invoiceAmount == 0) && $basicAmount !== null) {
                $invoiceAmount = round((float)$basicAmount + (float)($pfAmount ?? 0) + (float)($freightAmount ?? 0), 2);
            }
            $totalAmount = $validated['total_amount_purchase_invoice'] ?? $validated['total_amount'] ?? null;
            if (($totalAmount === null || $totalAmount === '' || (float)$totalAmount == 0) && $invoiceAmount !== null) {
                $totalAmount = round((float)$invoiceAmount + ((float)$invoiceAmount * (float)$gstTaxAmount / 100), 2);
            }
            if ($totalAmount === null) {
                $totalAmount = $inquiry->total_amount;
            }

            $itemRows = $validated['items'] ?? [];

            // If item_quantities passed instead of items, convert to items format for backward compatibility
            if (empty($itemRows) && !empty($validated['item_quantities'])) {
                foreach ($validated['item_quantities'] as $iq) {
                    $itemRows[] = [
                        'id' => $iq['id'] ?? null,
                        'qty' => $iq['quantity'] ?? 0,
                        'price' => $iq['price'] ?? 0,
                    ];
                }
            }

            // Scope header fields update to the batch items or inquiry group
            $headerItemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$id];

            Inquiry::whereIn('id', $headerItemIds)->update([
                'purchase_invoice_no' => $validated['purchase_invoice_no'] ?? $inquiry->purchase_invoice_no,
                'invoice_date' => $validated['invoice_date'] ?? $inquiry->invoice_date,
                'basic_amount' => $basicAmount,
                'pf_amount' => $pfAmount,
                'freight_amount' => $freightAmount,
                'invoice_amount' => $invoiceAmount,
                'total_amount' => $totalAmount,
                'basic_amount_purchase_invoice' => $basicAmount,
                'pf_amount_purchase_invoice' => $pfAmount,
                'freight_amount_purchase_invoice' => $freightAmount,
                'invoice_amount_purchase_invoice' => $invoiceAmount,
                'total_amount_purchase_invoice' => $totalAmount,
                'total_purchase_value' => $totalAmount ?? $inquiry->total_purchase_value,
                'gst_tax_amount' => $gstTaxAmount,
                'payment_due_date' => $validated['payment_due_date'] ?? $inquiry->payment_due_date,
                'purchase_payment_status' => $validated['purchase_payment_status'] ?? $inquiry->purchase_payment_status,
                'principal_id' => $validated['principal_id'] ?? $inquiry->principal_id,
                'inquiry_id' => $validated['enquiry_ref'] ?? $inquiry->inquiry_id,
                'status' => $validated['status'] ?? $inquiry->status,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            $updatedItemIds = [];
            $createdItemIds = [];

            if (!empty($itemRows)) {
                foreach ($itemRows as $itemRow) {
                    $qty = (float) ($itemRow['qty'] ?? $itemRow['quantity'] ?? 0);
                    if ($qty <= 0) {
                        continue;
                    }

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

                    $itemPrice = (float) ($itemRow['price'] ?? 0);

                    if (!empty($itemRow['id'])) {
                        // Existing item row — update in place
                        Inquiry::where('id', $itemRow['id'])->update([
                            'item_id' => $catalogItem->id ?? null,
                            'purchase_invoice_no' => $validated['purchase_invoice_no'],
                            'invoice_date' => $validated['invoice_date'],
                            'price' => $itemPrice,
                            'qty_invoiced' => $qty,
                            'unit_purchase_price' => $itemPrice,
                            'total_purchase_value' => $itemPrice,
                            'gst_tax_amount' => $gstTaxAmount,
                            'payment_due_date' => $validated['payment_due_date'],
                            'purchase_payment_status' => $validated['purchase_payment_status'],
                            'principal_id' => $validated['principal_id'] ?? $inquiry->principal_id,
                            'status' => $itemRow['status'] ?? $inquiry->status,
                            'updated_by' => Auth::id(),
                            'updated_at' => now(),
                        ]);
                        $updatedItemIds[] = $itemRow['id'];
                    } else {
                        // New item — insert as a child row
                        $childInquiry = Inquiry::create([
                            'parent_id' => $parentId,
                            'inquiry_code' => $inquiry->inquiry_code,
                            'inquiry_id' => $validated['enquiry_ref'] ?? $inquiry->inquiry_id,
                            'item_id' => $catalogItem->id ?? null,
                            'enquiry_type' => 'Purchase_Invoice',
                            'principal_id' => $validated['principal_id'] ?? $inquiry->principal_id,
                            'customer_id' => $inquiry->customer_id,
                            'purchase_invoice_no' => $validated['purchase_invoice_no'],
                            'invoice_date' => $validated['invoice_date'],
                            'quantity' => $qty,
                            'customer_qty_order' => 0,
                            'price' => $itemPrice,
                            'qty_invoiced' => $qty,
                            'unit_purchase_price' => $itemPrice,
                            'total_purchase_value' => $itemPrice,
                            'gst_tax_amount' => $gstTaxAmount,
                            'payment_due_date' => $validated['payment_due_date'],
                            'purchase_payment_status' => $validated['purchase_payment_status'],
                            'status' => $itemRow['status'] ?? 'Pending',
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
                'message' => 'Purchase Invoice updated successfully.',
                'updated_item_ids' => $updatedItemIds,
                'new_item_ids' => $createdItemIds,
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
     * Direct create — no source PO to Principal required. Used by the
     * manual "+" Add button, same idea as
     * PoToPrincipalController::store(). Resolves/creates the catalog
     * Item for each submitted row, merges the first item into the root
     * Inquiry row, and creates a child row for every item after that.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'purchase_invoice_no' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'basic_amount' => 'nullable|numeric|min:0',
                'pf_amount' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'invoice_amount' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'basic_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'pf_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'freight_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'invoice_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'total_amount_purchase_invoice' => 'nullable|numeric|min:0',
                'principal_id' => 'nullable|integer|exists:users,id',
                'enquiry_ref' => 'nullable|string|max:255',
                'gst_tax_amount' => 'nullable|numeric|min:0',
                'payment_due_date' => 'nullable|date',
                'purchase_payment_status' => 'nullable|in:Pending,Partial,Paid,30day credit',
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

            $prefix = 'INQ';

            $lastInquiry = Inquiry::orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $lastNumber = 0;
            if ($lastInquiry && preg_match('/(\d+)$/', $lastInquiry->inquiry_code, $matches)) {
                $lastNumber = (int) $matches[1];
            }

            $inquiryCode = $prefix . str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

            $basicAmount = $validated['basic_amount_purchase_invoice'] ?? $validated['basic_amount'] ?? null;
            $pfAmount = $validated['pf_amount_purchase_invoice'] ?? $validated['pf_amount'] ?? null;
            $freightAmount = $validated['freight_amount_purchase_invoice'] ?? $validated['freight_amount'] ?? null;
            $invoiceAmount = $validated['invoice_amount_purchase_invoice'] ?? $validated['invoice_amount'] ?? null;
            if (($invoiceAmount === null || $invoiceAmount === '' || (float)$invoiceAmount == 0) && $basicAmount !== null) {
                $invoiceAmount = round((float)$basicAmount + (float)($pfAmount ?? 0) + (float)($freightAmount ?? 0), 2);
            }
            $gstTaxAmount = $validated['gst_tax_amount'] ?? 0;
            $totalAmount = $validated['total_amount_purchase_invoice'] ?? $validated['total_amount'] ?? null;
            if (($totalAmount === null || $totalAmount === '' || (float)$totalAmount == 0) && $invoiceAmount !== null) {
                $totalAmount = round((float)$invoiceAmount + ((float)$invoiceAmount * (float)$gstTaxAmount / 100), 2);
            }

            // Resolve/create the catalog Item for every submitted row up
            // front (same pattern as PoToPrincipalController::store()),
            // and drop any row with no qty. Item catalog fields
            // (mat_code/item_name/description/part_model_no) live on the
            // `items` table, NOT on the Inquiry row.
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

            $gstTaxAmount = $validated['gst_tax_amount'] ?? 0;

            // The FIRST item merges straight into the root row instead of
            // getting a separate child row — same idea as
            // PoToPrincipalController::store().
            $firstItem = array_shift($preparedItems);

            $inquiry = Inquiry::create([
                'parent_id' => 0,
                'inquiry_code' => $inquiryCode,
                'inquiry_id' => $validated['enquiry_ref'] ?? $inquiryCode,
                'enquiry_type' => 'Purchase_Invoice',
                'item_id' => $firstItem['item_id'] ?? null,
                'principal_id' => $validated['principal_id'] ?? null,
                'purchase_invoice_no' => $validated['purchase_invoice_no'] ?? null,
                'invoice_date' => $validated['invoice_date'] ?? null,
                'basic_amount' => $basicAmount,
                'pf_amount' => $pfAmount,
                'freight_amount' => $freightAmount,
                'invoice_amount' => $invoiceAmount,
                'total_amount' => $totalAmount,
                'basic_amount_purchase_invoice' => $basicAmount,
                'pf_amount_purchase_invoice' => $pfAmount,
                'freight_amount_purchase_invoice' => $freightAmount,
                'invoice_amount_purchase_invoice' => $invoiceAmount,
                'total_amount_purchase_invoice' => $totalAmount,
                'quantity' => $firstItem['qty'],
                'price' => $firstItem['price'],
                'qty_invoiced' => $firstItem['qty'],
                'unit_purchase_price' => $firstItem['price'],
                'total_purchase_value' => $totalAmount ?? $firstItem['price'],
                'gst_tax_amount' => $gstTaxAmount,
                'payment_due_date' => $validated['payment_due_date'] ?? null,
                'purchase_payment_status' => $validated['purchase_payment_status'] ?? 'Pending',
                'status' => $firstItem['status'],
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
                    'enquiry_type' => 'Purchase_Invoice',
                    'principal_id' => $validated['principal_id'] ?? null,
                    'purchase_invoice_no' => $validated['purchase_invoice_no'] ?? null,
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'basic_amount' => $basicAmount,
                    'pf_amount' => $pfAmount,
                    'freight_amount' => $freightAmount,
                    'invoice_amount' => $invoiceAmount,
                    'total_amount' => $totalAmount,
                    'basic_amount_purchase_invoice' => $basicAmount,
                    'pf_amount_purchase_invoice' => $pfAmount,
                    'freight_amount_purchase_invoice' => $freightAmount,
                    'invoice_amount_purchase_invoice' => $invoiceAmount,
                    'total_amount_purchase_invoice' => $totalAmount,
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'qty_invoiced' => $item['qty'],
                    'unit_purchase_price' => $item['price'],
                    'total_purchase_value' => $totalAmount ?? $item['price'],
                    'gst_tax_amount' => $gstTaxAmount,
                    'payment_due_date' => $validated['payment_due_date'] ?? null,
                    'purchase_payment_status' => $validated['purchase_payment_status'] ?? 'Pending',
                    'status' => $item['status'],
                    'user_id' => Auth::id(),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                $createdItemIds[] = $childInquiry->id;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Purchase Invoice created successfully.',
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

    public function savePayLater(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'pay_later_payment_date' => 'nullable|date',
            ]);

            $inquiry = Inquiry::findOrFail($id);
            $parentId = $inquiry->parent_id ?: $inquiry->id;

            $query = Inquiry::where('parent_id', $parentId);
            if (!empty($inquiry->purchase_invoice_no)) {
                $query->where('purchase_invoice_no', $inquiry->purchase_invoice_no);
            }
            $query->update([
                'pay_later_payment_date' => $validated['pay_later_payment_date'],
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            Inquiry::where('id', $id)->update([
                'pay_later_payment_date' => $validated['pay_later_payment_date'],
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Pay later payment date saved successfully.',
                'data' => [
                    'id' => $id,
                    'pay_later_payment_date' => $validated['pay_later_payment_date'],
                ]
            ], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

}