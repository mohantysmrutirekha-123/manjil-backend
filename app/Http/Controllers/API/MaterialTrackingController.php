<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\MaterialShipmentBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MaterialTrackingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Inquiry::with([
                    'item:id,name,mat_code,part_model_no',
                    'shipmentBatches',
                    'customer:id,name',
                    'principal:id,name',
                    'parent.customer:id,name',
                    'parent.principal:id,name',
                ])
                ->whereIn('enquiry_type', ['PO_Principal', 'Purchase_Invoice', 'Sales_Invoice']);

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('po_principal_no', 'like', "%{$search}%")
                      ->orWhere('purchase_invoice_no', 'like', "%{$search}%")
                      ->orWhere('sales_invoice_no', 'like', "%{$search}%")
                      ->orWhere('inquiry_code', 'like', "%{$search}%")
                      ->orWhereHas('item', function ($iq) use ($search) {
                          $iq->where('mat_code', 'like', "%{$search}%")
                             ->orWhere('name', 'like', "%{$search}%");
                      });
                });
            }

            $rows = $query->latest('updated_at')->get();

            $data = $rows->map(function (Inquiry $row) {
                $totalQty = (float) ($row->total_qty_ordered_principal ?? $row->qty_invoiced ?? $row->qty_billed ?? $row->quantity ?? $row->customer_qty_order ?? 0);

                $batches = $row->shipmentBatches->map(function (MaterialShipmentBatch $b) use ($row) {
                    $invNo = $b->invoice_no ?: ($row->sales_invoice_no ?: $row->purchase_invoice_no);
                    return [
                        'id' => $b->id,
                        'batch_no' => $b->batch_no,
                        'qty' => (float) $b->qty,
                        'packagesCount' => $b->packages_count,
                        'status' => $b->status,
                        'condition' => $b->condition ?: 'Accepted',
                        'dispatchDate' => optional($b->dispatch_date)->format('Y-m-d'),
                        'expectedDelivery' => optional($b->expected_delivery)->format('Y-m-d'),
                        'actualReceiptDate' => optional($b->actual_receipt_date)->format('Y-m-d'),
                        'refDocType' => $b->ref_doc_type,
                        'refDocNo' => $b->ref_doc_no,
                        'refDoc' => $b->ref_doc_no
                            ? ($b->ref_doc_type ? "{$b->ref_doc_type}-{$b->ref_doc_no}" : $b->ref_doc_no)
                            : null,
                        'lrDate' => optional($b->lr_date)->format('Y-m-d'),
                        'invoiceNo' => $invNo,
                        'location' => $b->location,
                        'allocatedCustomerPo' => $b->allocated_customer_po,
                        'allocatedQty' => (float) ($b->allocated_qty ?? 0),
                        'transporter' => $b->transporter,
                        'remarks' => $b->remarks,
                    ];
                })->values();

                $deliveredQty = $batches->where('status', 'Delivered')->sum('qty');
                $acceptedQty = $batches->where('status', 'Delivered')->where('condition', 'Accepted')->sum('qty');
                $damagedQty = $batches->where('condition', 'Damaged')->sum('qty');
                $shortQty = $batches->where('condition', 'Short')->sum('qty');
                $mismatchQty = $batches->where('condition', 'Mismatch')->sum('qty');
                $allocatedQty = $batches->sum('allocatedQty');

                $shippedQty = $batches->sum('qty');
                $pendingQty = max(0, $totalQty - $shippedQty);

                $stage = match($row->enquiry_type) {
                    'PO_Principal' => 'PO to Principal',
                    'Purchase_Invoice' => 'Purchase Invoice',
                    'Sales_Invoice', 'Sell_Invoice' => 'Sales Invoice',
                    default => $row->enquiry_type,
                };

                return [
                    'id' => $row->id,
                    'mePo' => $row->po_principal_no ?: ($row->purchase_invoice_no ?: ($row->sales_invoice_no ?: $row->inquiry_code)),
                    'inquiryCode' => $row->inquiry_code,
                    'enquiryType' => $row->enquiry_type,
                    'stage' => $stage,
                    'purchaseInvoiceNo' => $row->purchase_invoice_no,
                    'salesInvoiceNo' => $row->sales_invoice_no,
                    'customerName' => optional($row->customer)->name ?? optional(optional($row->parent)->customer)->name,
                    'principalName' => optional($row->principal)->name ?? optional(optional($row->parent)->principal)->name,
                    'matCode' => optional($row->item)->mat_code,
                    'item' => optional($row->item)->name,
                    'promiseDate' => optional($row->promise_date ?? optional($row->parent)->promise_date)->format('Y-m-d'),
                    'originalPromiseDate' => optional($row->original_promise_date ?? optional($row->parent)->original_promise_date)->format('Y-m-d'),
                    'latestFollowupDate' => optional($row->latest_followup_date)->format('Y-m-d'),
                    'nextFollowupDate' => optional($row->next_followup_date)->format('Y-m-d'),
                    'principalProductionStatus' => $row->principal_production_status ?? 'Under Production',
                    'customerDueDate' => optional($row->customer_due_date ?? optional($row->parent)->customer_due_date)->format('Y-m-d'),
                    'rfqNo' => $row->inquiry_id,
                    'totalQty' => $totalQty,
                    'deliveredQty' => $deliveredQty,
                    'acceptedQty' => $acceptedQty,
                    'damagedQty' => $damagedQty,
                    'shortQty' => $shortQty,
                    'mismatchQty' => $mismatchQty,
                    'allocatedQty' => $allocatedQty,
                    'pendingQty' => $pendingQty,
                    'status' => $row->status,
                    'batches' => $batches,
                ];
            });

            return response()->json(['status' => true, 'message' => 'Material Tracking List', 'data' => $data], 200);

        } catch (\Exception $e) {
            Log::error('MaterialTracking index error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function storeBatch(Request $request, $inquiryItemId)
    {
        DB::beginTransaction();
        try {
            $item = Inquiry::whereIn('enquiry_type', ['PO_Principal', 'Purchase_Invoice', 'Sales_Invoice', 'Sell_Invoice'])->find($inquiryItemId);

            if (!$item) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Tracking item not found.'], 404);
            }

            $validated = $request->validate([
                'qty' => 'nullable|numeric',
                'packages_count' => 'nullable|max:100',
                'status' => 'nullable|in:Pending,Dispatched,In Transit,Delivered',
                'condition' => 'nullable|in:Accepted,Damaged,Short,Mismatch',
                'dispatch_date' => 'nullable|date',
                'expected_delivery' => 'nullable|date',
                'actual_receipt_date' => 'nullable|date',
                'ref_doc_type' => 'nullable|in:LR,OA,CO,SO',
                'ref_doc_no' => 'nullable|string|max:255',
                'lr_date' => 'nullable|date',
                'invoice_no' => 'nullable|string|max:255',
                'location' => 'nullable|string|max:255',
                'allocated_customer_po' => 'nullable|string|max:255',
                'allocated_qty' => 'nullable|numeric|min:0',
                'transporter' => 'nullable|string|max:255',
                'remarks' => 'nullable|string',
            ]);

            $nextBatchNo = (int) $item->shipmentBatches()->max('batch_no') + 1;

            $batch = MaterialShipmentBatch::create([
                'inquiry_item_id' => $item->id,
                'po_principal_no' => $item->po_principal_no ?: ($item->purchase_invoice_no ?: ($item->sales_invoice_no ?: $item->inquiry_code)),
                'batch_no' => $nextBatchNo ?: 1,
                'qty' => $validated['qty'],
                'packages_count' => $validated['packages_count'] ?? null,
                'status' => $validated['status'],
                'condition' => $validated['condition'] ?? 'Accepted',
                'dispatch_date' => $validated['dispatch_date'] ?? null,
                'expected_delivery' => $validated['expected_delivery'] ?? null,
                'actual_receipt_date' => $validated['actual_receipt_date'] ?? ($validated['status'] === 'Delivered' ? now()->toDateString() : null),
                'ref_doc_type' => $validated['ref_doc_type'] ?? null,
                'ref_doc_no' => $validated['ref_doc_no'] ?? null,
                'lr_date' => $validated['lr_date'] ?? null,
                'invoice_no' => $validated['invoice_no'] ?? ($item->sales_invoice_no ?: ($item->purchase_invoice_no ?: ($item->po_principal_no ?: ($item->inquiry_code ?: 'N/A')))),
                'location' => $validated['location'] ?? null,
                'allocated_customer_po' => $validated['allocated_customer_po'] ?? null,
                'allocated_qty' => $validated['allocated_qty'] ?? null,
                'transporter' => $validated['transporter'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json(['status' => true, 'message' => 'Shipment batch recorded.', 'data' => $batch], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('MaterialTracking storeBatch error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateBatch(Request $request, $batchId)
    {
        DB::beginTransaction();
        try {
            $batch = MaterialShipmentBatch::find($batchId);

            if (!$batch) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Batch not found.'], 404);
            }

            $validated = $request->validate([
                'qty' => 'sometimes|numeric|min:0.01',
                'packages_count' => 'nullable|max:100',
                'status' => 'sometimes|in:Pending,Dispatched,In Transit,Delivered',
                'condition' => 'nullable|in:Accepted,Damaged,Short,Mismatch',
                'dispatch_date' => 'nullable|date',
                'expected_delivery' => 'nullable|date',
                'actual_receipt_date' => 'nullable|date',
                'ref_doc_type' => 'nullable|in:LR,OA,CO,SO',
                'ref_doc_no' => 'nullable|string|max:255',
                'lr_date' => 'nullable|date',
                'invoice_no' => 'nullable|string|max:255',
                'location' => 'nullable|string|max:255',
                'allocated_customer_po' => 'nullable|string|max:255',
                'allocated_qty' => 'nullable|numeric|min:0',
                'transporter' => 'nullable|string|max:255',
                'remarks' => 'nullable|string',
            ]);

            $validated['updated_by'] = Auth::id();
            if (isset($validated['status']) && $validated['status'] === 'Delivered' && empty($validated['actual_receipt_date']) && empty($batch->actual_receipt_date)) {
                $validated['actual_receipt_date'] = now()->toDateString();
            }
            $batch->update($validated);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Batch updated.', 'data' => $batch], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('MaterialTracking updateBatch error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroyBatch($batchId)
    {
        $batch = MaterialShipmentBatch::find($batchId);
        if (!$batch) {
            return response()->json(['status' => false, 'message' => 'Batch not found.'], 404);
        }
        $batch->delete();
        return response()->json(['status' => true, 'message' => 'Batch deleted.'], 200);
    }

    public function bulkStoreBatch(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'item_ids' => 'nullable|array',
                'item_ids.*' => 'nullable|integer',
                'qty_each' => 'nullable|numeric',
                'status' => 'nullable|in:Pending,Dispatched,In Transit,Delivered',
                'condition' => 'nullable|in:Accepted,Damaged,Short,Mismatch',
                'dispatch_date' => 'nullable|date',
                'expected_delivery' => 'nullable|date',
            ]);

            $items = Inquiry::whereIn('enquiry_type', ['PO_Principal', 'Purchase_Invoice', 'Sales_Invoice', 'Sell_Invoice'])
                ->whereIn('id', $validated['item_ids'])
                ->with('shipmentBatches')
                ->get();

            $created = [];

            foreach ($items as $item) {
                $totalQty = (float) ($item->total_qty_ordered_principal ?? $item->qty_invoiced ?? $item->qty_billed ?? $item->quantity ?? $item->customer_qty_order ?? 0);
                $shippedQty = $item->shipmentBatches->sum('qty');
                $pending = max(0, $totalQty - $shippedQty);
                $moveQty = min((float) $validated['qty_each'], $pending);

                if ($moveQty <= 0) {
                    continue;
                }

                $nextBatchNo = (int) $item->shipmentBatches->max('batch_no') + 1;

                $created[] = MaterialShipmentBatch::create([
                    'inquiry_item_id' => $item->id,
                    'po_principal_no' => $item->po_principal_no ?: ($item->purchase_invoice_no ?: ($item->sales_invoice_no ?: $item->inquiry_code)),
                    'batch_no' => $nextBatchNo ?: 1,
                    'qty' => $moveQty,
                    'status' => $validated['status'],
                    'condition' => $validated['condition'] ?? 'Accepted',
                    'dispatch_date' => $validated['dispatch_date'] ?? now()->toDateString(),
                    'expected_delivery' => $validated['expected_delivery'] ?? null,
                    'invoice_no' => $item->sales_invoice_no ?: $item->purchase_invoice_no,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ])->id;
            }

            DB::commit();
            return response()->json([
                'status' => true,
                'message' => count($created) . ' batch(es) created.',
                'data' => $created,
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('MaterialTracking bulkStoreBatch error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
