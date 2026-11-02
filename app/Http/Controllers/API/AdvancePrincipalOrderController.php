<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdvancePrincipalOrderController extends Controller
{
    /**
     * Section 17: Create an Advance Principal Order (Customer PO Awaited)
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'po_principal_no'               => 'nullable|string|max:100',
                'po_date'                       => 'nullable|date',
                'principal_id'                  => 'nullable|integer',
                'principal_name'                => 'nullable|string|max:255',
                'expected_customer_name'        => 'nullable|string|max:255',
                'expected_customer_location'    => 'nullable|string|max:255',
                'expected_delivery_requirement' => 'nullable|string|max:255',
                'advance_order_reason'          => 'nullable|string',
                'item_id'                       => 'nullable|integer',
                'product_details'               => 'nullable|string',
                'quantity'                      => 'nullable|numeric',
                'unit_purchase_price'           => 'nullable|numeric|min:0',
                'basic_amount'                  => 'nullable|numeric|min:0',
                'pf_amount'                     => 'nullable|numeric|min:0',
                'freight_amount'                => 'nullable|numeric|min:0',
                'gst_amount'                    => 'nullable|numeric|min:0',
                'total_with_gst'                => 'nullable|numeric|min:0',
                'promise_date'                  => 'nullable|date',
                'remarks'                       => 'nullable|string',
            ]);

            // Auto-generate inquiry_code / internal ref if not present
            $inquiryCode = 'ADV-' . strtoupper(substr(uniqid(), -10));
            $poPrincipalNo = $validated['po_principal_no'] ?? ('ADV-' . date('ymd') . '-' . rand(100, 999));
            $poDate = $validated['po_date'] ?? now()->toDateString();

            $qty = (float) $validated['quantity'];
            $basic = (float) ($validated['basic_amount'] ?? (($validated['unit_purchase_price'] ?? 0) * $qty));
            $pf = (float) ($validated['pf_amount'] ?? 0);
            $freight = (float) ($validated['freight_amount'] ?? 0);
            $poAmt = $basic + $pf + $freight;
            $gst = (float) ($validated['gst_amount'] ?? 0);
            $total = (float) ($validated['total_with_gst'] ?? ($poAmt + ($poAmt * $gst / 100)));

            $inquiry = Inquiry::create([
                'inquiry_code'                  => $inquiryCode,
                'enquiry_type'                  => 'PO_Principal',
                'status'                        => 'Ordered Placed', // Standard enum
                'principal_production_status'   => 'Under Production',
                'is_advance_principal_order'    => true,
                'advance_order_reason'          => $validated['advance_order_reason'],
                'expected_customer_name'        => $validated['expected_customer_name'] ?? null,
                'expected_customer_location'    => $validated['expected_customer_location'] ?? null,
                'expected_delivery_requirement' => $validated['expected_delivery_requirement'] ?? null,
                'unallocated_qty'               => $qty,
                'po_principal_no'               => $poPrincipalNo,
                'po_date'                       => $poDate,
                'principal_id'                  => $validated['principal_id'] ?? null,
                'principal_name'                => $validated['principal_name'] ?? null,
                'item_id'                       => $validated['item_id'] ?? null,
                'product_details'               => $validated['product_details'] ?? null,
                'quantity'                      => $qty,
                'total_qty_ordered_principal'   => $qty,
                'unit_purchase_price'           => $validated['unit_purchase_price'] ?? null,
                'basic_amount'                  => $basic,
                'pf_amount'                     => $pf,
                'freight_amount'                => $freight,
                'po_amount_principal'           => $poAmt,
                'gst_amount'                    => $gst,
                'total_with_gst'                => $total,
                'promise_date'                  => $validated['promise_date'] ?? null,
                'original_promise_date'         => $validated['promise_date'] ?? null,
                'remarks'                       => $validated['remarks'] ?? null,
                'created_by'                    => Auth::id(),
            ]);

            // Parent ID self-link for hierarchy consistency
            $inquiry->update(['parent_id' => $inquiry->id]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Advance Principal Order created successfully (Customer PO Awaited).',
                'data'    => $inquiry,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create advance principal order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Section 17, Step 3: Link an incoming Customer PO to an existing Advance Principal Order
     */
    public function linkCustomerPo(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $inquiry = Inquiry::findOrFail($id);

            $validated = $request->validate([
                'customer_id'            => 'nullable|integer',
                'customer_name'          => 'nullable|string|max:255',
                'customer_po_no'         => 'nullable|string|max:100',
                'customer_po_date'       => 'nullable|date',
                'customer_due_date'      => 'nullable|date',
                'customer_payment_terms' => 'nullable|string|max:255',
                'ld_terms'               => 'nullable|string|max:255',
                'allocated_qty'          => 'nullable|numeric',
                'unit_selling_price'     => 'nullable|numeric|min:0',
                'total_selling_value'    => 'nullable|numeric|min:0',
            ]);

            $totalOrderedQty = (float) ($inquiry->total_qty_ordered_principal ?: $inquiry->quantity);
            $allocatedQty = (float) $validated['allocated_qty'];

            if ($allocatedQty > $totalOrderedQty) {
                return response()->json([
                    'status'  => false,
                    'message' => "Allocated quantity ({$allocatedQty}) cannot exceed the ordered factory quantity ({$totalOrderedQty}).",
                ], 422);
            }

            $unallocatedQty = max(0, $totalOrderedQty - $allocatedQty);

            $customerId = $validated['customer_id'] ?? null;
            if (!$customerId && !empty($validated['customer_name'])) {
                $custUser = User::where('name', $validated['customer_name'])->first();
                $customerId = $custUser ? $custUser->id : null;
            }

            // Update inquiry with confirmed customer PO details without erasing supplier data
            $inquiry->update([
                'customer_id'                 => $customerId ?? $inquiry->customer_id,
                'expected_customer_name'      => $validated['customer_name'],
                'customer_po_no'              => $validated['customer_po_no'],
                'customer_po_date'            => $validated['customer_po_date'] ?? now()->toDateString(),
                'customer_due_date'           => $validated['customer_due_date'] ?? null,
                'customer_payment_terms'      => $validated['customer_payment_terms'] ?? null,
                'ld_terms'                    => $validated['ld_terms'] ?? null,
                'price'                       => $validated['unit_selling_price'] ?? null,
                'total_selling_value'         => $validated['total_selling_value'] ?? (($validated['unit_selling_price'] ?? 0) * $allocatedQty),
                'unallocated_qty'             => $unallocatedQty,
                'is_advance_principal_order'  => false, // Transition from provisional to linked
                'updated_at'                  => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => "Customer PO successfully linked to Principal Order. (Allocated: {$allocatedQty}, Unallocated surplus: {$unallocatedQty})",
                'data'    => [
                    'inquiry'         => $inquiry,
                    'allocated_qty'   => $allocatedQty,
                    'unallocated_qty' => $unallocatedQty,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to link customer PO: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Section 16: Combine multiple Customer PO items into One Principal PO
     */
    public function combineCustomerOrders(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'po_principal_no'    => 'nullable|string|max:100',
                'po_date'            => 'nullable|date',
                'principal_id'       => 'nullable|integer',
                'principal_name'     => 'nullable|string|max:255',
                'promise_date'       => 'nullable|date',
                'buffer_surplus_qty' => 'nullable|numeric|min:0',
                'items'              => 'nullable|array',
                'items.*.customer_inquiry_id' => 'nullable|integer',
                'items.*.allocated_qty'       => 'nullable|numeric',
                'items.*.unit_purchase_price' => 'nullable|numeric|min:0',
            ]);

            $poPrincipalNo = $validated['po_principal_no'] ?? ('COMB-' . date('ymd') . '-' . rand(100, 999));
            $poDate = $validated['po_date'] ?? now()->toDateString();
            $bufferSurplus = (float) ($validated['buffer_surplus_qty'] ?? 0);

            $totalQty = $bufferSurplus;
            $totalBasic = 0;
            $combinedSourceIds = [];

            $items = $validated['items'] ?? [];
            foreach ($items as $it) {
                $cInq = Inquiry::find($it['customer_inquiry_id']);
                if ($cInq) {
                    $combinedSourceIds[] = [
                        'customer_inquiry_id' => $cInq->id,
                        'customer_po_no'      => $cInq->customer_po_no,
                        'customer_name'       => $cInq->customer_name,
                        'customer_due_date'   => optional($cInq->customer_due_date)->format('Y-m-d') ?? $cInq->customer_due_date,
                        'allocated_qty'       => (float) $it['allocated_qty'],
                    ];
                    $qty = (float) $it['allocated_qty'];
                    $totalQty += $qty;
                    $totalBasic += ($qty * (float) ($it['unit_purchase_price'] ?? 0));
                }
            }

            // Create the combined master PO to principal
            $inquiry = Inquiry::create([
                'inquiry_code'                => 'COMB-' . strtoupper(substr(uniqid(), -10)),
                'enquiry_type'                => 'PO_Principal',
                'status'                      => 'Ordered Placed',
                'principal_production_status' => 'Under Production',
                'is_combined_principal_order' => true,
                'combined_source_item_ids'    => $combinedSourceIds,
                'unallocated_qty'             => $bufferSurplus,
                'po_principal_no'             => $poPrincipalNo,
                'po_date'                     => $poDate,
                'principal_id'                => $validated['principal_id'] ?? null,
                'principal_name'              => $validated['principal_name'] ?? null,
                'total_qty_ordered_principal' => $totalQty,
                'quantity'                    => $totalQty,
                'basic_amount'                => $totalBasic,
                'po_amount_principal'         => $totalBasic,
                'promise_date'                => $validated['promise_date'] ?? null,
                'original_promise_date'       => $validated['promise_date'] ?? null,
                'created_by'                  => Auth::id(),
            ]);

            $inquiry->update(['parent_id' => $inquiry->id]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => "Combined Principal PO created successfully for {$totalQty} total units across " . count($combinedSourceIds) . " customer order(s).",
                'data'    => $inquiry,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to combine customer orders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Section 16: Re-allocate quantities between customer orders with audit reason
     */
    public function reallocate(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $inquiry = Inquiry::findOrFail($id);

            $validated = $request->validate([
                'reallocations' => 'nullable|array',
                'reason'        => 'nullable|string',
            ]);

            // Save previous allocation for audit trail
            $prevAllocation = $inquiry->combined_source_item_ids;

            $inquiry->update([
                'combined_source_item_ids' => $validated['reallocations'],
                'order_balance_cancelled_reason' => "Reallocation on " . now()->toDateTimeString() . " by " . (Auth::user()?->name ?? 'Team') . ". Reason: " . $validated['reason'],
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Quantity reallocation recorded successfully.',
                'data'    => [
                    'previous' => $prevAllocation,
                    'current'  => $validated['reallocations'],
                    'reason'   => $validated['reason'],
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to reallocate quantity: ' . $e->getMessage(),
            ], 500);
        }
    }
}
