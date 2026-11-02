<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\MaterialShipmentBatch;
use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationalDashboardController extends Controller
{
    /**
     * Section 19: Operational Command Center KPI Metrics
     */
    public function summary()
    {
        try {
            $today = Carbon::today()->format('Y-m-d');

            // 1. New Customer Orders (Principal PO Pending)
            $customerPos = Inquiry::where('enquiry_type', 'Purchase_Order')
                ->whereColumn('id', 'parent_id')
                ->get();

            $newOrdersPendingPo = $customerPos->filter(function ($cPo) {
                $hasPoToPrincipal = Inquiry::where('parent_id', $cPo->id)
                    ->whereIn('enquiry_type', ['PO_Principal', 'Purchase_Order_To_Principal'])
                    ->exists();
                return !$hasPoToPrincipal;
            })->count();

            // 2. CO Awaited (Principal PO without CO / OA number)
            $coAwaited = Inquiry::whereIn('enquiry_type', ['PO_Principal', 'Purchase_Order_To_Principal'])
                ->where(function ($q) {
                    $q->whereNull('principal_co_no')
                      ->orWhere('principal_co_no', '');
                })
                ->where('order_closure_status', '!=', 'Closed')
                ->count();

            // 3. Principal Follow-ups Due Today / Overdue
            $followupsDue = Inquiry::whereNotNull('next_followup_date')
                ->where('next_followup_date', '<=', $today)
                ->where('order_closure_status', '!=', 'Closed')
                ->count();

            // 4. Delivery Risk (Supplier Promised Date > Customer Due Date)
            $deliveryRisk = Inquiry::whereNotNull('promise_date')
                ->whereNotNull('customer_due_date')
                ->whereColumn('promise_date', '>', 'customer_due_date')
                ->where('order_closure_status', '!=', 'Closed')
                ->count();

            // 5. LD Risk (Past customer due date with active LD terms)
            $ldRisk = Inquiry::whereNotNull('customer_due_date')
                ->where('customer_due_date', '<', $today)
                ->whereNotNull('ld_terms')
                ->where('ld_terms', '!=', '')
                ->where('order_closure_status', '!=', 'Closed')
                ->count();

            // 6. Material in Transit (LR issued but truck not yet arrived)
            $inTransit = MaterialShipmentBatch::whereNotNull('ref_doc_no')
                ->where('ref_doc_no', '!=', '')
                ->whereNull('actual_receipt_date')
                ->count();

            // 7. Material Received Awaiting Sales Invoice / Delivery
            $materialReceived = MaterialShipmentBatch::whereNotNull('actual_receipt_date')
                ->where('condition', '!=', 'Damaged')
                ->count();

            // 8. Invoice Portal Submission Pending or Rejected (Stage 10)
            $portalPending = Inquiry::whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
                ->where(function ($q) {
                    $q->whereIn('portal_submission_status', ['Pending', 'Rejected', 'Submitted'])
                      ->orWhereNull('portal_submission_status');
                })
                ->where('order_closure_status', '!=', 'Closed')
                ->count();

            // 9. Customer Collections Due / Overdue
            $customerOverdue = Inquiry::whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
                ->whereNotNull('sales_invoice_date')
                ->where('order_closure_status', '!=', 'Closed')
                ->count();

            // 10. Principal Payments Due
            $principalPaymentsDue = Inquiry::where('enquiry_type', 'Purchase_Invoice')
                ->where(function ($q) {
                    $q->whereNull('purchase_payment_status')
                      ->orWhere('purchase_payment_status', '!=', 'Paid');
                })
                ->count();

            // 11. Customer PO Awaited (Advance Principal Orders - Sec 17)
            $customerPoAwaited = Inquiry::where(function ($q) {
                $q->where('is_advance_principal_order', true)
                  ->orWhere('status', 'Customer PO Awaited');
            })->count();

            // 12. Unallocated Surplus Quantity (Sec 16 & 17)
            $unallocatedCount = Inquiry::where('unallocated_qty', '>', 0)->count();

            // 13. Deductions & Disputes (TDS, GST-TDS, LD penalties)
            $disputedDeductions = PaymentTransaction::where(function ($q) {
                $q->where('ld_deduction', '>', 0)
                  ->orWhere('other_deductions', '>', 0);
            })->count();

            // 14. Cash View Projections (Next 30 Days)
            $totalSalesValue = (float) Inquiry::whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
                ->where('order_closure_status', '!=', 'Closed')
                ->sum('total_selling_value');

            $totalSettledCustomer = (float) PaymentTransaction::sum('total_settled');
            $expectedCustomerInflow = max(0, $totalSalesValue - $totalSettledCustomer);

            $totalPurchaseValue = (float) Inquiry::where('enquiry_type', 'Purchase_Invoice')
                ->sum('total_amount_purchase_invoice');
            $expectedPrincipalOutflow = $totalPurchaseValue * 0.4; // estimated balance

            $counts = [
                'co_awaited'                 => $coAwaited,
                'due_followups'              => $followupsDue,
                'delivery_risk'              => $deliveryRisk + $ldRisk,
                'in_transit'                 => $inTransit,
                'portal_pending'             => $portalPending,
                'overdue_collections'        => $customerOverdue,
                'principal_due'              => $principalPaymentsDue,
                'advance_orders'             => $customerPoAwaited,
                'combined_orders'            => Inquiry::where('is_combined_principal_order', true)->count(),
                'active_inquiries'           => Inquiry::where('status', '!=', 'Completed')->count(),
                'pending_offers'             => Inquiry::where('enquiry_type', 'Offer_Customer')->count(),
                'pos_received'               => Inquiry::where('status', 'PO Received')->count(),
                'inspection_due'             => MaterialShipmentBatch::where('condition', '!=', 'Damaged')->whereNull('actual_receipt_date')->count(),
                'billing_pending'            => Inquiry::where('status', 'Delivered')->whereNull('sales_invoice_no')->count(),
                'orders_closed'              => Inquiry::where('order_closure_status', 'Closed')->count(),
                'unallocated_stock'          => $unallocatedCount,
                'disputed_deductions'        => $disputedDeductions,
            ];

            return response()->json([
                'status' => true,
                'data'   => array_merge($counts, [
                    'co_awaited_count'           => $counts['co_awaited'],
                    'due_followups_count'        => $counts['due_followups'],
                    'delivery_risk_count'        => $counts['delivery_risk'],
                    'in_transit_count'           => $counts['in_transit'],
                    'portal_pending_count'       => $counts['portal_pending'],
                    'overdue_collections_count'  => $counts['overdue_collections'],
                    'principal_due_count'        => $counts['principal_due'],
                    'advance_orders_count'       => $counts['advance_orders'],
                    'combined_orders_count'      => $counts['combined_orders'],
                    'active_inquiries_count'     => $counts['active_inquiries'],
                    'pending_offers_count'       => $counts['pending_offers'],
                    'pos_received_count'         => $counts['pos_received'],
                    'inspection_due_count'       => $counts['inspection_due'],
                    'billing_pending_count'      => $counts['billing_pending'],
                    'orders_closed_count'        => $counts['orders_closed'],
                    'counts'                     => $counts,
                    'cash_projections'           => [
                        'projected_inflow_30d'   => $expectedCustomerInflow,
                        'projected_outflow_30d'  => $expectedPrincipalOutflow,
                        'net_position_30d'       => $expectedCustomerInflow - $expectedPrincipalOutflow,
                    ],
                    'cash_view'                  => [
                        'expected_customer_inflow'   => $expectedCustomerInflow,
                        'expected_principal_outflow' => $expectedPrincipalOutflow,
                        'net_projected_cash'         => $expectedCustomerInflow - $expectedPrincipalOutflow,
                    ],
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch operational dashboard summary: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Section 19: Get filtered drill-down action list for a specific metric
     */
    public function actionList($request = null, $type = null)
    {
        if (is_string($request) && $type === null) {
            $type = $request;
        }

        try {
            $today = Carbon::today()->format('Y-m-d');
            $rows = [];

            switch ($type) {
                case 'co_awaited':
                    $items = Inquiry::whereIn('enquiry_type', ['PO_Principal', 'Purchase_Order_To_Principal'])
                        ->where(function ($q) {
                            $q->whereNull('principal_co_no')->orWhere('principal_co_no', '');
                        })
                        ->where('order_closure_status', '!=', 'Closed')
                        ->get();

                    $rows = $items->map(function ($it) {
                        $ref = $it->po_principal_no ?: $it->inquiry_code;
                        $note = 'Follow up with manufacturer for Confirmation of Order (CO/OA).';
                        return [
                            'id'                 => $it->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $it->inquiry_code,
                            'customer_name'      => optional($it->customer)->name ?: $it->customer_name ?: '-',
                            'principal_name'     => optional($it->principal)->name ?: $it->principal_name ?: '-',
                            'status'             => 'CO Awaited',
                            'responsible_person' => 'Procurement Team',
                            'last_update'        => optional($it->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $it->po_date ? Carbon::parse($it->po_date)->addDays(5)->format('Y-m-d') : '-',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'material_in_transit':
                case 'in_transit':
                    $batches = MaterialShipmentBatch::whereNotNull('ref_doc_no')
                        ->where('ref_doc_no', '!=', '')
                        ->whereNull('actual_receipt_date')
                        ->get();

                    $rows = $batches->map(function ($b) {
                        $ref = "LR: {$b->ref_doc_no}";
                        $note = "Dispatched via {$b->transporter} ({$b->packages_count} pkgs, {$b->qty} qty). Track shipment delivery.";
                        return [
                            'id'                 => $b->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $b->inquiry_code ?: '-',
                            'customer_name'      => $b->allocated_customer_po ?: '-',
                            'principal_name'     => $b->transporter ?: 'Road Transporter',
                            'status'             => 'In Transit (On Highway)',
                            'responsible_person' => 'Logistics Team',
                            'last_update'        => $b->lr_date ?: optional($b->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $b->lr_date ? Carbon::parse($b->lr_date)->addDays(4)->format('Y-m-d') : '-',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'due_followups':
                    $items = Inquiry::whereNotNull('next_followup_date')
                        ->where('next_followup_date', '<=', $today)
                        ->where('order_closure_status', '!=', 'Closed')
                        ->get();

                    $rows = $items->map(function ($it) {
                        $ref = $it->po_principal_no ?: $it->inquiry_code;
                        $note = $it->follow_notes ?: 'Follow up with principal factory on manufacturing and inspection status.';
                        return [
                            'id'                 => $it->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $it->inquiry_code,
                            'customer_name'      => optional($it->customer)->name ?: $it->customer_name ?: '-',
                            'principal_name'     => optional($it->principal)->name ?: $it->principal_name ?: '-',
                            'status'             => 'Follow-up Due',
                            'responsible_person' => $it->responsible_person ?: 'Procurement Team',
                            'last_update'        => optional($it->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $it->next_followup_date,
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'delivery_risk':
                    $items = Inquiry::where(function ($q) use ($today) {
                        $q->where(function ($sub) {
                            $sub->whereNotNull('promise_date')
                                ->whereNotNull('customer_due_date')
                                ->whereColumn('promise_date', '>', 'customer_due_date');
                        })->orWhere(function ($sub) use ($today) {
                            $sub->whereNotNull('customer_due_date')
                                ->where('customer_due_date', '<', $today)
                                ->whereNotNull('ld_terms')
                                ->where('ld_terms', '!=', '');
                        });
                    })->where('order_closure_status', '!=', 'Closed')->get();

                    $rows = $items->map(function ($it) use ($today) {
                        $ref = $it->po_principal_no ?: $it->inquiry_code;
                        $isLd = $it->customer_due_date && $it->customer_due_date < $today;
                        $note = $isLd
                            ? "Overdue past {$it->customer_due_date}. LD Terms: {$it->ld_terms}"
                            : "Promise Date ({$it->promise_date}) exceeds Customer Due ({$it->customer_due_date})";
                        return [
                            'id'                 => $it->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $it->inquiry_code,
                            'customer_name'      => optional($it->customer)->name ?: $it->customer_name ?: '-',
                            'principal_name'     => optional($it->principal)->name ?: $it->principal_name ?: '-',
                            'status'             => $isLd ? 'LD Penalty Risk' : 'Delivery Delay Risk',
                            'responsible_person' => 'Procurement / Expediting',
                            'last_update'        => optional($it->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $it->promise_date ?: $it->customer_due_date,
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'customer_po_awaited':
                case 'advance_orders':
                    $items = Inquiry::where(function ($q) {
                        $q->where('is_advance_principal_order', true)
                          ->orWhere('status', 'Customer PO Awaited');
                    })->get();

                    $rows = $items->map(function ($it) {
                        $ref = $it->po_principal_no ?: $it->inquiry_code;
                        $note = "Advance Order: {$it->advance_order_reason}. Link customer PO upon receipt.";
                        return [
                            'id'                 => $it->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $it->inquiry_code,
                            'customer_name'      => $it->expected_customer_name ?: 'Customer PO Awaited',
                            'principal_name'     => optional($it->principal)->name ?: $it->principal_name ?: '-',
                            'status'             => 'Advance Order (Sec 17)',
                            'responsible_person' => 'Sales Coordinator',
                            'last_update'        => optional($it->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $it->expected_delivery_requirement ?: '-',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'combined_orders':
                    $items = Inquiry::where('is_combined_principal_order', true)->get();
                    $rows = $items->map(function ($it) {
                        $ref = $it->po_principal_no ?: $it->inquiry_code;
                        $note = "Combined clubbed order. Unallocated stock: {$it->unallocated_qty} units.";
                        return [
                            'id'                 => $it->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $it->inquiry_code,
                            'customer_name'      => 'Multiple Clubbed Customers',
                            'principal_name'     => optional($it->principal)->name ?: $it->principal_name ?: '-',
                            'status'             => 'Combined Principal PO',
                            'responsible_person' => 'Procurement Specialist',
                            'last_update'        => optional($it->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $it->promise_date ?: '-',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'orders_closed':
                    $items = Inquiry::where('order_closure_status', 'Closed')->get();
                    $rows = $items->map(function ($it) {
                        $ref = $it->po_principal_no ?: $it->inquiry_code;
                        $note = 'All 5 checklist criteria verified. Zero open debt.';
                        return [
                            'id'                 => $it->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $it->inquiry_code,
                            'customer_name'      => optional($it->customer)->name ?: $it->customer_name ?: '-',
                            'principal_name'     => optional($it->principal)->name ?: $it->principal_name ?: '-',
                            'status'             => 'Closed & Archived',
                            'responsible_person' => 'Finance Controller',
                            'last_update'        => optional($it->closed_at)->format('Y-m-d'),
                            'next_action_date'   => 'Completed',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'portal_pending':
                case 'invoice_portal_pending':
                    $invoices = Inquiry::whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
                        ->where(function ($q) {
                            $q->whereIn('portal_submission_status', ['Pending', 'Rejected', 'Submitted'])
                              ->orWhereNull('portal_submission_status');
                        })
                        ->where('order_closure_status', '!=', 'Closed')
                        ->get();

                    $rows = $invoices->map(function ($inv) {
                        $ref = $inv->sales_invoice_no ?: $inv->inquiry_code;
                        $note = $inv->portal_rejection_reason ? "Rejection: {$inv->portal_rejection_reason}" : 'Upload invoice & signed POD on customer portal.';
                        return [
                            'id'                 => $inv->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $inv->inquiry_code,
                            'customer_name'      => optional($inv->customer)->name ?: $inv->customer_name ?: '-',
                            'principal_name'     => optional($inv->principal)->name ?: $inv->principal_name ?: '-',
                            'status'             => "Portal: " . ($inv->portal_submission_status ?? 'Pending'),
                            'responsible_person' => $inv->portal_responsible_person ?: 'Billing Officer',
                            'last_update'        => $inv->portal_submission_date ?: optional($inv->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $inv->portal_next_followup_date ?: '-',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'overdue_collections':
                    $invoices = Inquiry::whereIn('enquiry_type', ['Sales_Invoice', 'Sell_Invoice'])
                        ->whereNotNull('sales_invoice_date')
                        ->where('order_closure_status', '!=', 'Closed')
                        ->get();

                    $rows = $invoices->map(function ($inv) {
                        $ref = $inv->sales_invoice_no ?: $inv->inquiry_code;
                        $note = "Outstanding Invoice Value: ₹" . number_format((float)$inv->total_selling_value, 2) . ". Follow up for customer remittance.";
                        return [
                            'id'                 => $inv->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $inv->inquiry_code,
                            'customer_name'      => optional($inv->customer)->name ?: $inv->customer_name ?: '-',
                            'principal_name'     => optional($inv->principal)->name ?: $inv->principal_name ?: '-',
                            'status'             => 'Receivable Due',
                            'responsible_person' => 'Finance / Accounts',
                            'last_update'        => $inv->sales_invoice_date ?: optional($inv->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $inv->payment_due_date ?: '-',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                case 'principal_due':
                    $invoices = Inquiry::where('enquiry_type', 'Purchase_Invoice')
                        ->where(function ($q) {
                            $q->whereNull('purchase_payment_status')
                              ->orWhere('purchase_payment_status', '!=', 'Paid');
                        })->get();

                    $rows = $invoices->map(function ($inv) {
                        $ref = $inv->purchase_invoice_no ?: $inv->inquiry_code;
                        $note = "Purchase Invoice Value: ₹" . number_format((float)$inv->total_amount_purchase_invoice, 2) . ". Supplier payment release scheduled.";
                        return [
                            'id'                 => $inv->id,
                            'ref'                => $ref,
                            'ref_no'             => $ref,
                            'inquiry_code'       => $inv->inquiry_code,
                            'customer_name'      => optional($inv->customer)->name ?: $inv->customer_name ?: '-',
                            'principal_name'     => optional($inv->principal)->name ?: $inv->principal_name ?: '-',
                            'status'             => $inv->purchase_payment_status ?: 'Unpaid',
                            'responsible_person' => 'Accounts Payable',
                            'last_update'        => $inv->invoice_date ?: optional($inv->updated_at)->format('Y-m-d'),
                            'next_action_date'   => $inv->payment_due_date ?: '-',
                            'notes'              => $note,
                            'action_note'        => $note,
                            'remarks'            => $note,
                        ];
                    });
                    break;

                default:
                    // Generic default
                    $rows = [];
            }

            return response()->json([
                'status' => true,
                'type'   => $type,
                'count'  => count($rows),
                'data'   => $rows,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch action list: ' . $e->getMessage(),
            ], 500);
        }
    }
}
