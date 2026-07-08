<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
                'purchase_invoice_no'     => 'required|string|max:255',
                'invoice_date'            => 'required|date',
                'unit_purchase_price'     => 'required|numeric|min:0',
                'qty_invoiced'            => 'required|numeric|min:1',
                'gst_tax_amount'          => 'nullable|numeric|min:0',
                'payment_due_date'        => 'required|date',
                'purchase_payment_status' => 'required|in:Pending,Partial,Paid',
            ]);

            $totalPurchaseValue = $validated['unit_purchase_price'] * $validated['qty_invoiced'];

            Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
                ->update([
                    'enquiry_type'            => 'Purchase_Invoice',
                    'purchase_invoice_no'     => $validated['purchase_invoice_no'],
                    'invoice_date'            => $validated['invoice_date'],
                    'unit_purchase_price'     => $validated['unit_purchase_price'],
                    'qty_invoiced'            => $validated['qty_invoiced'],
                    'gst_tax_amount'          => $validated['gst_tax_amount'] ?? 0,
                    'total_purchase_value'    => $totalPurchaseValue,
                    'payment_due_date'        => $validated['payment_due_date'],
                    'purchase_payment_status' => $validated['purchase_payment_status'],
                    'updated_by'              => Auth::id(),
                    'updated_at'              => now(),
                ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Purchase Invoice created successfully.',
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

    public function purchaseinvoiceList()
    {
        try {

            $enquiries = Inquiry::with([
                'customer:id,name',
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->whereColumn('id', 'parent_id')
            ->whereIn('enquiry_type', ['Purchase_Invoice','Sales_Invoice'])
            ->latest()
            ->get();

            $data = [];

            foreach ($enquiries as $enquiry) {

                $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
                    ->where('parent_id', $enquiry->id)
                    ->whereIn('enquiry_type', ['Purchase_Invoice', 'Sales_Invoice'])
                    ->get();

                $data[] = [
                    'id'                       => $enquiry->id,
                    'parent_id'                => $enquiry->parent_id,
                    'inquiry_id'               => $enquiry->inquiry_id,
                    'inquiry_code'             => $enquiry->inquiry_code,
                    'enquiry_type'             => $enquiry->enquiry_type,
                    'customer_id'              => $enquiry->customer_id,
                    'customer_name'            => optional($enquiry->customer)->name,
                    'principal_id'             => $enquiry->principal_id,
                    'po_principal_no'          => $enquiry->po_principal_no,
                    'purchase_invoice_no'      => $enquiry->purchase_invoice_no,
                    'invoice_date'             => $enquiry->invoice_date,
                    'unit_purchase_price'      => $enquiry->unit_purchase_price,
                    'qty_invoiced'             => $enquiry->qty_invoiced,
                    'gst_tax_amount'           => $enquiry->gst_tax_amount,
                    'total_purchase_value'     => $enquiry->total_purchase_value,
                    'payment_due_date'         => $enquiry->payment_due_date,
                    'purchase_payment_status'  => $enquiry->purchase_payment_status,
                    'status'                   => $enquiry->status,

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
                            'status'        => $row->status,
                            'date'          => optional($row->created_at)->format('Y-m-d'),
                        ];
                    })->values()
                ];
            }

            return response()->json(['status' => true, 'message' => 'Purchase Invoice List', 'data' => $data], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {

            $validated = $request->validate([
                'purchase_invoice_no'     => 'required|string|max:255',
                'invoice_date'            => 'required|date',
                'unit_purchase_price'     => 'required|numeric|min:0',
                'qty_invoiced'            => 'required|numeric|min:1',
                'gst_tax_amount'          => 'nullable|numeric|min:0',
                'payment_due_date'        => 'required|date',
                'purchase_payment_status' => 'required|in:Pending,Partial,Paid',
            ]);

            $inquiry = Inquiry::findOrFail($id);
            $parentId = $inquiry->parent_id;

            $totalPurchaseValue = $validated['unit_purchase_price'] * $validated['qty_invoiced'];

            Inquiry::where('parent_id', $parentId)->update([
                'purchase_invoice_no'     => $validated['purchase_invoice_no'],
                'invoice_date'            => $validated['invoice_date'],
                'unit_purchase_price'     => $validated['unit_purchase_price'],
                'qty_invoiced'            => $validated['qty_invoiced'],
                'gst_tax_amount'          => $validated['gst_tax_amount'] ?? 0,
                'total_purchase_value'    => $totalPurchaseValue,
                'payment_due_date'        => $validated['payment_due_date'],
                'purchase_payment_status' => $validated['purchase_payment_status'],
                'updated_by'              => Auth::id(),
                'updated_at'              => now(),
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Purchase Invoice updated successfully.'], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }











public function store(Request $request)
{
    DB::beginTransaction();

    try {

        $validated = $request->validate([

            'inquiry_id'   => 'required|string|max:20',
            'inquiry_code' => 'required|string|max:20',

            'principal_id' => 'required|exists:users,id',

            'purchase_invoice_no'               => 'required|string|max:255',
            'invoice_date'                  => 'required|date',
            'total_qty_ordered_principal'   => 'required|numeric|min:1',
            'qty_received_principal'        => 'nullable|numeric|min:0',
            'released_date'                 => 'nullable|date',

            'status' => 'required|in:Price Requested,Pending,Closed,Completed,Regret',

            'principal_po_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Check Duplicate Inquiry ID
        |--------------------------------------------------------------------------
        */

        if (Inquiry::where('inquiry_id', $validated['inquiry_id'])->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Inquiry ID already exists.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Check Duplicate Inquiry Code
        |--------------------------------------------------------------------------
        */

        if (Inquiry::where('inquiry_code', $validated['inquiry_code'])->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Inquiry Code already exists.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Upload Principal PO Document
        |--------------------------------------------------------------------------
        */

        $document = null;

        if ($request->hasFile('principal_po_document')) {

            $file = $request->file('principal_po_document');

            $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

            $file->move(
                public_path('uploads/principal_po_documents'),
                $document
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Calculate Pending Qty
        |--------------------------------------------------------------------------
        */

        $totalQtyOrdered = $validated['total_qty_ordered_principal'];

        $qtyReceived = $validated['qty_received_principal'] ?? 0;

        $pendingQty = max(0, $totalQtyOrdered - $qtyReceived);

        /*
        |--------------------------------------------------------------------------
        | Insert Record
        |--------------------------------------------------------------------------
        */

        $inquiry = Inquiry::create([

            'parent_id' => 0,

            'inquiry_code' => $validated['inquiry_code'],
            'inquiry_id'   => $validated['inquiry_id'],

            'enquiry_type' => 'PO_Principal',

            'principal_id' => $validated['principal_id'],

            'status' => $validated['status'],

            'po_principal_no' => $validated['po_principal_no'],

            'promise_date' => $validated['promise_date'],

            'total_qty_ordered_principal' => $totalQtyOrdered,

            'qty_received_principal' => $qtyReceived,

            'pending_qty_principal' => $pendingQty,

            'released_date' => $validated['released_date'],

            'principal_po_document' => $document,

            'user_id' => Auth::id(),

            'created_by' => Auth::id(),

            'updated_by' => Auth::id(),
        ]);

        // Make self parent
        $inquiry->update([
            'parent_id' => $inquiry->id
        ]);

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Principal PO created successfully.',
            'data' => $inquiry
        ], 201);

    } catch (ValidationException $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}




    
}