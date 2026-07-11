<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
                'sales_invoice_no'    => 'required|string|max:255',
                'sales_invoice_date'  => 'required|date',
                'gst_percent'         => 'nullable|numeric|min:0',
                'qty_billed'          => 'required|numeric|min:1',
                'total_selling_value' => 'required|numeric|min:0',
                'sales_invoice_status'=> 'required|in:Generated,Issued,Paid',
            ]);

            $qtyOrderedTotal = (int) $firstInquiry->customer_qty_order;
            $pendingAfterBill = max(0, $qtyOrderedTotal - $validated['qty_billed']);

            Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
                ->update([
                    'enquiry_type'           => 'Sales_Invoice',
                    'sales_invoice_no'       => $validated['sales_invoice_no'],
                    'sales_invoice_date'     => $validated['sales_invoice_date'],
                    'gst_percent'            => $validated['gst_percent'] ?? 0,
                    'qty_billed'             => $validated['qty_billed'],
                    'total_selling_value'    => $validated['total_selling_value'],
                    'pending_qty_after_bill' => $pendingAfterBill,
                    'sales_invoice_status'   => $validated['sales_invoice_status'],
                    'updated_by'             => Auth::id(),
                    'updated_at'             => now(),
                ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Sales Invoice created successfully.',
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

    public function sellinvoiceList()
    {
        try {

            $enquiries = Inquiry::with([
                'customer:id,name',
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->whereColumn('id', 'parent_id')
            ->whereIn('enquiry_type', ['Sales_Invoice'])
            ->latest()
            ->get();

            $data = [];

            foreach ($enquiries as $enquiry) {

                $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
                    ->where('parent_id', $enquiry->id)
                    ->whereIn('enquiry_type', ['Sales_Invoice'])
                    ->get();

                $data[] = [
                    'id'                     => $enquiry->id,
                    'parent_id'              => $enquiry->parent_id,
                    'inquiry_id'             => $enquiry->inquiry_id,
                    'inquiry_code'           => $enquiry->inquiry_code,
                    'enquiry_type'           => $enquiry->enquiry_type,
                    'customer_id'            => $enquiry->customer_id,
                    'customer_name'          => optional($enquiry->customer)->name,
                    'principal_id'           => $enquiry->principal_id,
                    'purchase_invoice_no'    => $enquiry->purchase_invoice_no,
                    'sales_invoice_no'       => $enquiry->sales_invoice_no,
                    'sales_invoice_date'     => $enquiry->sales_invoice_date,
                    'gst_percent'            => $enquiry->gst_percent,
                    'qty_billed'             => $enquiry->qty_billed,
                    'total_selling_value'    => $enquiry->total_selling_value,
                    'pending_qty_after_bill' => $enquiry->pending_qty_after_bill,
                    'sales_invoice_status'   => $enquiry->sales_invoice_status,
                   

                    'items' => $items->map(function ($row) {
                        return [
                            'id'            => $row->id,
                            'item_id'       => $row->item_id,
                            'material_code' => optional($row->item)->mat_code,
                            'item_name'     => optional($row->item)->name,
                            'description'   => optional($row->item)->description,
                            'part_model_no' => optional($row->item)->part_model_no,
                            'quantity'      => $row->quantity,
                            'price'         => $row->price,
                            'sales_invoice_status'        => $row->sales_invoice_status,
                            'date'          => optional($row->created_at)->format('Y-m-d'),
                        ];
                    })->values()
                ];
            }

            return response()->json(['status' => true, 'message' => 'Sales Invoice List', 'data' => $data], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $validated = $request->validate([
                'sales_invoice_no'     => 'required|string|max:255',
                'sales_invoice_date'   => 'required|date',
                'gst_percent'          => 'nullable|numeric|min:0',
                'qty_billed'           => 'required|numeric|min:1',
                'total_selling_value'  => 'required|numeric|min:0',
                'sales_invoice_status' => 'required|in:Generated,Issued,Paid',
            ]);

            $inquiry = Inquiry::findOrFail($id);
            $parentId = $inquiry->parent_id;

            $qtyOrderedTotal = (int) $inquiry->customer_qty_order;
            $pendingAfterBill = max(0, $qtyOrderedTotal - $validated['qty_billed']);

            Inquiry::where('parent_id', $parentId)->update([
                'sales_invoice_no'       => $validated['sales_invoice_no'],
                'sales_invoice_date'     => $validated['sales_invoice_date'],
                'gst_percent'            => $validated['gst_percent'] ?? 0,
                'qty_billed'             => $validated['qty_billed'],
                'total_selling_value'    => $validated['total_selling_value'],
                'pending_qty_after_bill' => $pendingAfterBill,
                'sales_invoice_status'   => $validated['sales_invoice_status'],
                'updated_by'             => Auth::id(),
                'updated_at'             => now(),
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Sales Invoice updated successfully.'], 200);

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