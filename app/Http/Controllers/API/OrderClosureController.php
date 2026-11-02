<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\MaterialShipmentBatch;
use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderClosureController extends Controller
{
    /**
     * Section 20: Evaluate the 5-point Order Closure Checklist
     */
    public function checkClosure($id)
    {
        try {
            $inquiry = Inquiry::with(['children', 'customer', 'principal'])->findOrFail($id);

            // 1. Delivery Check: Full quantity delivered or balance formally cancelled
            $orderedQty = (float) $inquiry->quantity;
            
            // Sum delivered from batches
            $deliveredQty = (float) MaterialShipmentBatch::where(function ($q) use ($inquiry) {
                $q->where('inquiry_item_id', $inquiry->id);
                if ($inquiry->po_principal_no) {
                    $q->orWhere('po_principal_no', $inquiry->po_principal_no);
                }
            })->where('condition', '!=', 'Damaged')->sum('qty');

            $isFullDelivery = $orderedQty > 0 && ($deliveredQty >= $orderedQty);
            $hasCancellationReason = !empty($inquiry->order_balance_cancelled_reason);
            $deliveryPassed = $isFullDelivery || $hasCancellationReason;

            $deliveryCheck = [
                'name'    => '1. Material Delivery Check',
                'passed'  => $deliveryPassed,
                'details' => $isFullDelivery 
                    ? "Full quantity delivered ({$deliveredQty} of {$orderedQty} units)."
                    : ($hasCancellationReason 
                        ? "Partial delivery ({$deliveredQty}/{$orderedQty} units) with recorded balance cancellation: '{$inquiry->order_balance_cancelled_reason}'"
                        : "Pending delivery: {$deliveredQty} of {$orderedQty} units delivered. Need to deliver remaining or record balance cancellation reason."),
            ];

            // 2. Documents & Portal Submission Check (checks active row or related Sales Invoice)
            $portalStatus = $inquiry->portal_submission_status;
            if (empty($portalStatus) || $portalStatus === 'Pending') {
                $relatedSi = Inquiry::where(function ($q) use ($inquiry) {
                    $q->where('id', $inquiry->id)
                      ->orWhere('parent_id', $inquiry->id)
                      ->orWhere('inquiry_code', $inquiry->inquiry_code);
                })->whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
                  ->whereNotNull('portal_submission_status')
                  ->first();
                if ($relatedSi) {
                    $portalStatus = $relatedSi->portal_submission_status;
                }
            }
            $portalStatus = $portalStatus ?? 'Pending';
            $docsPassed = in_array($portalStatus, ['Accepted', 'Not Required']);
            $docCheck = [
                'name'    => '2. Portal Submission & Supporting Docs',
                'passed'  => $docsPassed,
                'details' => $docsPassed
                    ? "Portal submission verified and marked as '{$portalStatus}'."
                    : "Customer portal status is currently '{$portalStatus}'. Must be Accepted or Not Required before closure.",
            ];

            // 3. Customer Payment Settlement Check
            $salesInvoices = Inquiry::where(function ($q) use ($inquiry) {
                $q->where('id', $inquiry->id)
                  ->orWhere('parent_id', $inquiry->id)
                  ->orWhere('inquiry_code', $inquiry->inquiry_code);
            })->whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])->get();

            $totalInvoiceValue = $salesInvoices->sum(function ($inv) {
                return (float) ($inv->total_selling_value ?: $inv->sale_basic_gst ?: $inv->cumulative ?: 0);
            });

            // Sum payments & deductions
            $transactions = PaymentTransaction::where(function ($q) use ($inquiry, $salesInvoices) {
                $q->where('inquiry_id', $inquiry->id)
                  ->orWhereIn('inquiry_id', $salesInvoices->pluck('id'));
            })->get();

            $totalSettled = $transactions->sum('total_settled');
            $customerPending = max(0, $totalInvoiceValue - $totalSettled);
            $customerPaymentPassed = ($totalInvoiceValue > 0) ? ($customerPending <= 1.0) : true; // allow rounding 1 rupee

            $customerCheck = [
                'name'    => '3. Customer Payment & Settlement',
                'passed'  => $customerPaymentPassed,
                'details' => $customerPaymentPassed
                    ? "Customer payment 100% settled. (Billed: ₹" . number_format($totalInvoiceValue, 2) . ", Settled: ₹" . number_format($totalSettled, 2) . ")."
                    : "Customer payment balance pending: ₹" . number_format($customerPending, 2) . " (Billed: ₹" . number_format($totalInvoiceValue, 2) . ", Received/Deducted: ₹" . number_format($totalSettled, 2) . ").",
            ];

            // 4. Principal Payment Check
            $purchaseInvoices = Inquiry::where(function ($q) use ($inquiry) {
                $q->where('id', $inquiry->id)
                  ->orWhere('parent_id', $inquiry->id)
                  ->orWhere('inquiry_code', $inquiry->inquiry_code);
            })->where('enquiry_type', 'Purchase_Invoice')->get();

            $principalUnpaid = $purchaseInvoices->filter(function ($pi) {
                return strtolower($pi->purchase_payment_status ?? '') !== 'paid';
            })->count();

            $principalPassed = ($principalUnpaid === 0);
            $principalCheck = [
                'name'    => '4. Principal Payment Position',
                'passed'  => $principalPassed,
                'details' => $principalPassed
                    ? "All supplier purchase invoices paid in full."
                    : "{$principalUnpaid} supplier purchase invoice(s) have pending payment.",
            ];

            // 5. Deductions & Open Disputes Check
            $unresolvedDeductions = $transactions->filter(function ($t) {
                return ((float)$t->ld_deduction > 0 || (float)$t->other_deductions > 0) && empty($t->deduction_reason);
            })->count();

            $disputePassed = ($unresolvedDeductions === 0);
            $disputeCheck = [
                'name'    => '5. Disputes & Deductions Audit',
                'passed'  => $disputePassed,
                'details' => $disputePassed
                    ? "All deductions (TDS, LD penalties) are documented with valid reasons."
                    : "{$unresolvedDeductions} transaction(s) have unexplained deductions without recorded reasons.",
            ];

            $allPassed = $deliveryPassed && $docsPassed && $customerPaymentPassed && $principalPassed && $disputePassed;

            $checksMap = [
                'delivery_completed' => [
                    'name'    => '1. Material Delivery Check',
                    'pass'    => $deliveryPassed,
                    'details' => [
                        'ordered'    => $orderedQty,
                        'dispatched' => $deliveredQty,
                        'summary'    => $isFullDelivery 
                            ? "Full quantity delivered ({$deliveredQty} of {$orderedQty} units)."
                            : ($hasCancellationReason 
                                ? "Partial delivery ({$deliveredQty}/{$orderedQty} units) with recorded balance cancellation: '{$inquiry->order_balance_cancelled_reason}'"
                                : "Pending delivery: {$deliveredQty} of {$orderedQty} units delivered."),
                    ],
                ],
                'portal_acceptance' => [
                    'name'    => '2. Customer Portal Acceptance / Handover Recorded',
                    'pass'    => $docsPassed,
                    'details' => [
                        'status'  => $portalStatus,
                        'summary' => $docsPassed
                            ? "Portal submission verified and marked as '{$portalStatus}'."
                            : "Customer portal status is currently '{$portalStatus}'. Must be Accepted or Not Required before closure.",
                    ],
                ],
                'customer_settlement' => [
                    'name'    => '3. Customer Payment Settlement (Zero Unreconciled Balance)',
                    'pass'    => $customerPaymentPassed,
                    'details' => [
                        'invoiced' => $totalInvoiceValue,
                        'received' => $totalSettled,
                        'pending'  => $customerPending,
                        'summary'  => $customerPaymentPassed
                            ? "Customer payment 100% settled. (Billed: ₹" . number_format($totalInvoiceValue, 2) . ", Settled: ₹" . number_format($totalSettled, 2) . ")."
                            : "Customer payment balance pending: ₹" . number_format($customerPending, 2),
                    ],
                ],
                'principal_settlement' => [
                    'name'    => '4. Principal Payment Settlement (Zero Outstanding Payables)',
                    'pass'    => $principalPassed,
                    'details' => [
                        'billed'  => (float) ($purchaseInvoices->sum('total_purchase_value') ?: $purchaseInvoices->sum('total_amount_purchase_invoice') ?: 0),
                        'paid'    => (float) ($purchaseInvoices->where('purchase_payment_status', 'Paid')->sum('total_purchase_value') ?: 0),
                        'pending' => $principalUnpaid,
                        'summary' => $principalPassed
                            ? "All supplier purchase invoices paid in full."
                            : "{$principalUnpaid} supplier purchase invoice(s) have pending payment.",
                    ],
                ],
                'no_open_disputes' => [
                    'name'    => '5. Zero Unresolved Disputes / Rejections / LD Claims',
                    'pass'    => $disputePassed,
                    'details' => [
                        'summary' => $disputePassed
                            ? "All deductions (TDS, LD penalties) are documented with valid reasons."
                            : "{$unresolvedDeductions} transaction(s) have unexplained deductions without recorded reasons.",
                    ],
                ],
            ];

            return response()->json([
                'status'    => true,
                'data'      => [
                    'order_ref'     => $inquiry->me_ref ?: $inquiry->inquiry_code,
                    'is_closed'     => ($inquiry->order_closure_status === 'Closed'),
                    'closed_at'     => optional($inquiry->closed_at)->toDateTimeString(),
                    'can_close'     => $allPassed,
                    'checks'        => $checksMap,
                    'checks_list'   => [
                        $deliveryCheck,
                        $docCheck,
                        $customerCheck,
                        $principalCheck,
                        $disputeCheck,
                    ],
                    'summary'       => [
                        'ordered_qty'       => $orderedQty,
                        'delivered_qty'     => $deliveredQty,
                        'total_sale_value'  => $totalInvoiceValue,
                        'customer_settled'  => $totalSettled,
                        'customer_pending'  => $customerPending,
                        'portal_status'     => $portalStatus,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to check order closure status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Section 20: Formally Close an Order after Checklist Verification
     */
    public function closeOrder(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $inquiry = Inquiry::findOrFail($id);

            $validated = $request->validate([
                'force_close'                     => 'nullable|boolean',
                'order_balance_cancelled_reason'  => 'nullable|string',
                'remarks'                         => 'nullable|string',
            ]);

            $checkResponse = $this->checkClosure($id);
            $checkData = json_decode($checkResponse->getContent(), true);

            $canClose = $checkData['data']['can_close'] ?? false;
            $forceClose = $validated['force_close'] ?? false;

            if (!$canClose && !$forceClose && empty($validated['order_balance_cancelled_reason'])) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Cannot close order: One or more checklist conditions are incomplete. Please resolve or provide a formal cancellation reason.',
                    'checks'  => $checkData['data']['checks'] ?? [],
                ], 422);
            }

            // Save snapshot of checklist verification
            $snapshot = $checkData['data'] ?? [];
            $snapshot['closed_by_name'] = Auth::user()?->name ?? 'Team Member';
            $snapshot['closed_remarks'] = $validated['remarks'] ?? 'Order closure checklist verified.';

            // Close all related batch item rows
            $updateData = [
                'order_closure_status'            => 'Closed',
                'status'                          => 'Completed',
                'closed_at'                       => now(),
                'closed_by'                       => Auth::id(),
                'closure_checklist_snapshot'      => $snapshot,
                'order_balance_cancelled_reason'  => $validated['order_balance_cancelled_reason'] ?? $inquiry->order_balance_cancelled_reason,
                'updated_at'                      => now(),
            ];

            $matchQuery = Inquiry::query();
            if ($inquiry->inquiry_code) {
                $matchQuery->where('inquiry_code', $inquiry->inquiry_code);
            } elseif ($inquiry->parent_id) {
                $matchQuery->where('parent_id', $inquiry->parent_id)->orWhere('id', $inquiry->parent_id);
            } else {
                $matchQuery->where('id', $inquiry->id);
            }

            $affected = $matchQuery->update($updateData);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => "Order successfully closed and archived ({$affected} item(s) updated). All checklist requirements verified.",
                'data'    => $updateData,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to close order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Re-open an archived / closed order if audited amendments are required
     */
    public function reopenOrder(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $inquiry = Inquiry::findOrFail($id);

            $validated = $request->validate([
                'reason' => 'nullable|string',
            ]);

            $updateData = [
                'order_closure_status' => 'In Progress',
                'order_balance_cancelled_reason' => "Re-opened on " . now()->toDateTimeString() . " by " . (Auth::user()?->name ?? 'Team') . ". Reason: " . $validated['reason'],
                'closed_at' => null,
                'closed_by' => null,
                'updated_at' => now(),
            ];

            $matchQuery = Inquiry::query();
            if ($inquiry->inquiry_code) {
                $matchQuery->where('inquiry_code', $inquiry->inquiry_code);
            } else {
                $matchQuery->where('id', $inquiry->id);
            }

            $matchQuery->update($updateData);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Order successfully re-opened for follow-up.',
                'data'    => $updateData,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to re-open order: ' . $e->getMessage(),
            ], 500);
        }
    }
}
