<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentTrackingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Inquiry::with([
                    'customer:id,name',
                    'principal:id,name',
                    'paymentTransactions',
                ])
                ->whereIn('enquiry_type', ['Purchase_Invoice', 'Sales_Invoice']);

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('purchase_invoice_no', 'like', "%{$search}%")
                      ->orWhere('sales_invoice_no', 'like', "%{$search}%")
                      ->orWhere('inquiry_code', 'like', "%{$search}%");
                });
            }

            $rows = $query->latest('updated_at')->get();

            $data = $rows->map(function (Inquiry $row) {
                $isSales = $row->enquiry_type === 'Sales_Invoice';

                $invoiceId = $isSales ? $row->sales_invoice_no : $row->purchase_invoice_no;
                $type = $isSales ? 'Incoming' : 'Outgoing';
                $party = $isSales ? optional($row->customer)->name : optional($row->principal)->name;

                $itemQty = (float) ($row->quantity ?? 0);
                $itemPrice = (float) ($row->price ?? 0);
                if ($isSales) {
                    $gstAmount = (float) ($row->sale_basic_gst ?? 0);
                    $sellingAmount = (float) ($row->total_selling_value ?? 0);
                    $amount = $gstAmount > 0 
                        ? $gstAmount 
                        : ($sellingAmount > 0 
                            ? $sellingAmount 
                            : ($itemPrice > 0 ? ($itemQty > 0 ? $itemPrice * $itemQty : $itemPrice) : 0));
                } else {
                    $gstAmount = (float) ($row->purchase_basic_gst ?? 0);
                    $purchaseAmount = (float) ($row->total_purchase_value ?? 0);
                    $amount = $gstAmount > 0 
                        ? $gstAmount 
                        : ($purchaseAmount > 0 
                            ? $purchaseAmount 
                            : ($itemPrice > 0 ? ($itemQty > 0 ? $itemPrice * $itemQty : $itemPrice) : 0));
                }

                $dueDateRaw = $isSales
                    ? ($row->sales_invoice_date ?? $row->getRawOriginal('sales_invoice_date'))
                    : ($row->payment_due_date ?? $row->getRawOriginal('payment_due_date'));

                $dueDate = $dueDateRaw
                    ? (is_string($dueDateRaw) ? substr($dueDateRaw, 0, 10) : $dueDateRaw->format('Y-m-d'))
                    : null;

                $payLaterDateRaw = $row->pay_later_payment_date ?? $row->getRawOriginal('pay_later_payment_date');
                if (!$payLaterDateRaw && !empty($row->inquiry_code)) {
                    $payLaterDateRaw = Inquiry::where('inquiry_code', $row->inquiry_code)
                        ->whereNotNull('pay_later_payment_date')
                        ->value('pay_later_payment_date');
                }

                $payLaterDate = $payLaterDateRaw
                    ? (is_string($payLaterDateRaw) ? substr($payLaterDateRaw, 0, 10) : $payLaterDateRaw->format('Y-m-d'))
                    : null;

                $payLaterStatus = $row->pay_later_status ?: 'Pending';

                $transactions = $row->paymentTransactions->map(function (PaymentTransaction $t) {
                    $tds = (float) ($t->tds_amount ?? 0);
                    $gstTds = (float) ($t->gst_tds_amount ?? 0);
                    $ld = (float) ($t->ld_deduction ?? 0);
                    $other = (float) ($t->other_deductions ?? 0);
                    $adv = (float) ($t->advance_adjusted ?? 0);
                    $amt = (float) $t->amount;
                    $settled = (float) ($t->total_settled > 0 ? $t->total_settled : ($amt + $tds + $gstTds + $ld + $other + $adv));

                    return [
                        'id' => $t->id,
                        'amount' => $amt,
                        'tdsAmount' => $tds,
                        'gstTdsAmount' => $gstTds,
                        'ldDeduction' => $ld,
                        'otherDeductions' => $other,
                        'deductionReason' => $t->deduction_reason,
                        'advanceAdjusted' => $adv,
                        'totalSettled' => $settled,
                        'status' => $t->status,
                        'date' => optional($t->transaction_date)->format('Y-m-d'),
                        'mode' => $t->payment_mode,
                        'referenceNo' => $t->reference_no,
                        'notes' => $t->notes,
                    ];
                })->values();

                $completedTxns = $transactions->where('status', 'Completed');
                $paidAmount = $completedTxns->sum('amount');
                $totalTds = $completedTxns->sum('tdsAmount');
                $totalGstTds = $completedTxns->sum('gstTdsAmount');
                $totalLd = $completedTxns->sum('ldDeduction');
                $totalOtherDeductions = $completedTxns->sum('otherDeductions');
                $totalDeductions = $totalTds + $totalGstTds + $totalLd + $totalOtherDeductions;
                $totalAdvanceAdjusted = $completedTxns->sum('advanceAdjusted');
                $settledAmount = $completedTxns->sum('totalSettled');
                if ($settledAmount <= 0) {
                    $settledAmount = $paidAmount + $totalDeductions + $totalAdvanceAdjusted;
                }

                $pendingAmount = max(0, $amount - $settledAmount);

                return [
                    'id' => $row->id,
                    'invoiceId' => $invoiceId,
                    'invoiceCode' => $row->inquiry_code,
                    'type' => $type,
                    'party' => $party,
                    'amount' => $amount,
                    'quantity' => $itemQty,
                    'unitPrice' => $itemPrice,
                    'paidAmount' => $paidAmount,
                    'totalDeductions' => $totalDeductions,
                    'tdsAmount' => $totalTds,
                    'gstTdsAmount' => $totalGstTds,
                    'ldDeduction' => $totalLd,
                    'otherDeductions' => $totalOtherDeductions,
                    'advanceAdjusted' => $totalAdvanceAdjusted,
                    'settledAmount' => $settledAmount,
                    'pendingAmount' => $pendingAmount,
                    'dueDate' => $dueDate,
                    'dueDateBasis' => $row->due_date_basis ?: 'Invoice Date',
                    'advanceReceived' => (float) ($row->advance_received ?? 0),
                    'payLaterPaymentDate' => $payLaterDate,
                    'pay_later_payment_date' => $payLaterDate,
                    'payLaterStatus' => $payLaterStatus,
                    'pay_later_status' => $payLaterStatus,
                    'status' => $isSales ? $row->sales_invoice_status : $row->purchase_payment_status,
                    'transactions' => $transactions,
                ];
            });

            return response()->json(['status' => true, 'message' => 'Payment Tracking List', 'data' => $data], 200);

        } catch (\Exception $e) {
            Log::error('PaymentTracking index error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function storeTransaction(Request $request, $inquiryId)
    {
        DB::beginTransaction();
        try {
            $invoice = Inquiry::whereIn('enquiry_type', ['Purchase_Invoice', 'Sales_Invoice'])
                ->find($inquiryId);

            if (!$invoice) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
            }

            $validated = $request->validate([
                'amount' => 'nullable|numeric|min:0',
                'tds_amount' => 'nullable|numeric|min:0',
                'gst_tds_amount' => 'nullable|numeric|min:0',
                'ld_deduction' => 'nullable|numeric|min:0',
                'other_deductions' => 'nullable|numeric|min:0',
                'deduction_reason' => 'nullable|string',
                'advance_adjusted' => 'nullable|numeric|min:0',
                'status' => 'nullable|in:Initiated,Processing,Completed,Failed',
                'transaction_date' => 'nullable|date',
                'payment_mode' => 'nullable|in:Bank Transfer (NEFT/RTGS),UPI,Cheque,Cash,Letter of Credit,Advance Adjustment',
                'reference_no' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);

            $amt = (float) ($validated['amount'] ?? 0);
            $tds = (float) ($validated['tds_amount'] ?? 0);
            $gstTds = (float) ($validated['gst_tds_amount'] ?? 0);
            $ld = (float) ($validated['ld_deduction'] ?? 0);
            $other = (float) ($validated['other_deductions'] ?? 0);
            $adv = (float) ($validated['advance_adjusted'] ?? 0);

            $totalSettled = $amt + $tds + $gstTds + $ld + $other + $adv;

            if ($totalSettled <= 0) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Total payment amount or deduction/advance adjustment must be greater than 0.'
                ], 422);
            }

            $isSales = $invoice->enquiry_type === 'Sales_Invoice';

            $transaction = PaymentTransaction::create([
                'inquiry_id' => $invoice->id,
                'invoice_type' => $isSales ? 'Incoming' : 'Outgoing',
                'invoice_no' => $isSales ? $invoice->sales_invoice_no : $invoice->purchase_invoice_no,
                'amount' => $amt,
                'tds_amount' => $tds,
                'gst_tds_amount' => $gstTds,
                'ld_deduction' => $ld,
                'other_deductions' => $other,
                'deduction_reason' => $validated['deduction_reason'] ?? null,
                'advance_adjusted' => $adv,
                'total_settled' => $totalSettled,
                'status' => $validated['status'],
                'transaction_date' => $validated['transaction_date'] ?? now()->toDateString(),
                'payment_mode' => $validated['payment_mode'] ?? null,
                'reference_no' => $validated['reference_no'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            if ($adv > 0) {
                $invoice->increment('advance_adjusted', $adv);
            }

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Payment recorded.', 'data' => $transaction], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PaymentTracking storeTransaction error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateTransaction(Request $request, $transactionId)
    {
        DB::beginTransaction();
        try {
            $transaction = PaymentTransaction::find($transactionId);

            if (!$transaction) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Transaction not found.'], 404);
            }

            $validated = $request->validate([
                'amount' => 'sometimes|numeric|min:0',
                'tds_amount' => 'nullable|numeric|min:0',
                'gst_tds_amount' => 'nullable|numeric|min:0',
                'ld_deduction' => 'nullable|numeric|min:0',
                'other_deductions' => 'nullable|numeric|min:0',
                'deduction_reason' => 'nullable|string',
                'advance_adjusted' => 'nullable|numeric|min:0',
                'status' => 'sometimes|in:Initiated,Processing,Completed,Failed',
                'transaction_date' => 'nullable|date',
                'payment_mode' => 'nullable|in:Bank Transfer (NEFT/RTGS),UPI,Cheque,Cash,Letter of Credit,Advance Adjustment',
                'reference_no' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);

            $amt = isset($validated['amount']) ? (float) $validated['amount'] : (float) $transaction->amount;
            $tds = isset($validated['tds_amount']) ? (float) $validated['tds_amount'] : (float) $transaction->tds_amount;
            $gstTds = isset($validated['gst_tds_amount']) ? (float) $validated['gst_tds_amount'] : (float) $transaction->gst_tds_amount;
            $ld = isset($validated['ld_deduction']) ? (float) $validated['ld_deduction'] : (float) $transaction->ld_deduction;
            $other = isset($validated['other_deductions']) ? (float) $validated['other_deductions'] : (float) $transaction->other_deductions;
            $adv = isset($validated['advance_adjusted']) ? (float) $validated['advance_adjusted'] : (float) $transaction->advance_adjusted;

            $validated['total_settled'] = $amt + $tds + $gstTds + $ld + $other + $adv;
            $validated['updated_by'] = Auth::id();

            $transaction->update($validated);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Transaction updated.', 'data' => $transaction], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PaymentTracking updateTransaction error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateDueDateBasis(Request $request, $inquiryId)
    {
        try {
            $invoice = Inquiry::findOrFail($inquiryId);
            $validated = $request->validate([
                'due_date_basis' => 'nullable|string|in:Invoice Date,Delivery Date,Submission Date,Acceptance Date,Payment due date pending',
                'due_date' => 'nullable|date',
            ]);

            $invoice->due_date_basis = $validated['due_date_basis'];
            if (isset($validated['due_date'])) {
                if ($invoice->enquiry_type === 'Sales_Invoice') {
                    $invoice->sales_invoice_date = $validated['due_date'];
                } else {
                    $invoice->payment_due_date = $validated['due_date'];
                }
            }
            $invoice->save();

            return response()->json(['status' => true, 'message' => 'Due date basis updated.'], 200);
        } catch (\Exception $e) {
            Log::error('PaymentTracking updateDueDateBasis error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroyTransaction($transactionId)
    {
        $transaction = PaymentTransaction::find($transactionId);
        if (!$transaction) {
            return response()->json(['status' => false, 'message' => 'Transaction not found.'], 404);
        }
        $transaction->delete();
        return response()->json(['status' => true, 'message' => 'Transaction deleted.'], 200);
    }

    public function bulkStoreTransaction(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'inquiry_ids' => 'nullable|array',
                'inquiry_ids.*' => 'nullable|integer',
                'amount_each' => 'nullable|numeric',
                'status' => 'nullable|in:Initiated,Processing,Completed,Failed',
                'transaction_date' => 'nullable|date',
                'payment_mode' => 'nullable|string',
            ]);

            $invoices = Inquiry::whereIn('enquiry_type', ['Purchase_Invoice', 'Sales_Invoice'])
                ->whereIn('id', $validated['inquiry_ids'])
                ->get();

            $created = [];

            foreach ($invoices as $inv) {
                $isSales = $inv->enquiry_type === 'Sales_Invoice';
                $created[] = PaymentTransaction::create([
                    'inquiry_id' => $inv->id,
                    'invoice_type' => $isSales ? 'Incoming' : 'Outgoing',
                    'invoice_no' => $isSales ? $inv->sales_invoice_no : $inv->purchase_invoice_no,
                    'amount' => $validated['amount_each'],
                    'total_settled' => $validated['amount_each'],
                    'status' => $validated['status'],
                    'transaction_date' => $validated['transaction_date'] ?? now()->toDateString(),
                    'payment_mode' => $validated['payment_mode'] ?? 'Bank Transfer (NEFT/RTGS)',
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ])->id;
            }

            DB::commit();
            return response()->json([
                'status' => true,
                'message' => count($created) . ' payment(s) recorded.',
                'data' => $created,
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PaymentTracking bulkStoreTransaction error: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function markPayLaterPaid(Request $request, $id)
    {
        try {
            $invoice = Inquiry::findOrFail($id);
            $newStatus = $request->input('status', 'Paid');
            $invoice->pay_later_status = $newStatus;
            $invoice->save();

            return response()->json(['status' => true, 'message' => 'Status updated successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
