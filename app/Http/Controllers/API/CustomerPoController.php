<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CustomerPoController extends Controller
{


public function convertTocustomerpo(Request $request, $id)
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

        // $validated = $request->validate([
        //     'customer_po_no'        => 'required|string|max:255',
        //     'customer_due_date'     => 'required|date',
        //     'customer_qty_order'    => 'required|numeric|min:1',
        //     'me_ref'                => 'required|string|max:255',
        //     'pending_qty'           => 'nullable|numeric|min:0',
        //     'status'                => 'required|in:Price Requested,Pending,Closed,Completed,Regret',

        //     'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',

           
        // ]);

        $validated = $request->validate([
        'customer_po_no'        => 'required|string|max:255',
        'customer_due_date'     => 'required|date',
        'customer_qty_order'    => 'required|numeric|min:1',
        'me_ref'                => 'required|string|max:255',
        'pending_qty'           => 'nullable|numeric|min:0',
        'status'                => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
        'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        'item_ids'               => 'required|array|min:1',
        'item_ids.*'             => 'required|exists:inquiries,id',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Upload Customer PO Document
        |--------------------------------------------------------------------------
        */

        $document = null;

        if ($request->hasFile('customer_pdo_document')) {

            $file = $request->file('customer_pdo_document');

            $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

            $file->move(public_path('uploads/customer_po_documents'), $document);
        }

        /*
        |--------------------------------------------------------------------------
        | Update Inquiry Items
        |--------------------------------------------------------------------------
        */

        
        // -----------------------Original --------------

            // Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
            //     ->update([
            //         'enquiry_type'         => 'Customer_PO',
            //         'customer_po_no'       => $validated['customer_po_no'],
            //         'customer_due_date'    => $validated['customer_due_date'],
            //         'customer_qty_order'   => $validated['customer_qty_order'],
            //         'me_ref'               => $validated['me_ref'],
            //         'pending_qty'          => $validated['pending_qty'] ?? null,
            //         'customer_pdo_document'=> $document,
            //         'status'               => $validated['status'],
            //         'updated_by'           => Auth::id(),
            //         'updated_at'           => now(),
            //     ]);

            // Inquiry::whereIn('id', $validated['item_ids'])
            // ->where('inquiry_code', $firstInquiry->inquiry_code)
            // ->update([
            //     'enquiry_type'         => 'Customer_PO',
            //     'customer_po_no'       => $validated['customer_po_no'],
            //     'customer_due_date'    => $validated['customer_due_date'],
            //     'customer_qty_order'   => $validated['customer_qty_order'],
            //     'me_ref'               => $validated['me_ref'],
            //     'pending_qty'          => $validated['pending_qty'] ?? null,
            //     'customer_pdo_document'=> $document,
            //     'status'               => $validated['status'],
            //     'updated_by'           => Auth::id(),
            //     'updated_at'           => now(),
            // ]);

            Inquiry::whereIn('id', $validated['item_ids'])
            ->where('inquiry_code', $firstInquiry->inquiry_code)
            ->update([
                'enquiry_type'          => 'Customer_PO',
                'customer_po_no'        => $validated['customer_po_no'],
                'customer_due_date'     => $validated['customer_due_date'],
                'customer_qty_order'    => $validated['customer_qty_order'],
                'me_ref'                => $validated['me_ref'],
                'pending_qty'           => $validated['pending_qty'] ?? null,
                'customer_pdo_document' => $document,
                'status'                => $validated['status'],
                'updated_by'            => Auth::id(),
                'updated_at'            => now(),
            ]);
        

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Customer PO created successfully.',
            'file' => $document
        ], 200);

    } catch (ValidationException $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {

        DB::rollBack();

        Log::error('Customer PO Error: ' . $e->getMessage());

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}




public function customerpoList()
{
    try {

        // Parent enquiries only
        $enquiries = Inquiry::with([
            'customer:id,name',
            'item:id,name,mat_code,part_model_no,description'
        ])
        ->whereColumn('id', 'parent_id')
        //   ->where('enquiry_type', 'Customer_PO')
        ->whereIn('enquiry_type', ['Customer_PO', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'])
        ->latest()
        ->get();

        $data = [];

        foreach ($enquiries as $enquiry) {

            // Get all child items for this enquiry
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            //  ->where('enquiry_type', 'Customer_PO')
            ->whereIn('enquiry_type', ['Customer_PO', 'PO_Principal','Sales_Invoice', 'Purchase_Invoice'])
            ->get();

            $data[] = [
                'id'                   => $enquiry->id,
                'parent_id'            => $enquiry->parent_id,
                'inquiry_id'           => $enquiry->inquiry_id,
                'inquiry_code'         => $enquiry->inquiry_code,
                'enquiry_type'         => $enquiry->enquiry_type,
                'customer_id'          => $enquiry->customer_id,
                'customer_name'        => optional($enquiry->customer)->name,
                'principal_id'         => $enquiry->principal_id,
                'principal_quote_no'   => $enquiry->principal_quote_no,
                'principal_quote_date' => $enquiry->principal_quote_date,
                'offer_type'         => $enquiry->offer_type,
                'quantity_quoted'    => $enquiry->quantity_quoted,
                'validity_period'    => $enquiry->validity_period,
                'negotiation_details' => $enquiry->negotiation_details,
                'follow_notes'        => $enquiry->follow_notes,
                'discussion_dates'    => $enquiry->discussion_dates,
                'revised_value'       => $enquiry->revised_value,
                'price'                => $enquiry->price,
                'status'               => $enquiry->status,
                'quotation_no'         => $enquiry->quotation_no,
                'budgetary_no'          => $enquiry->budgetary_no,
                'customer_po_no'       => $enquiry->customer_po_no,
                'customer_due_date'    => $enquiry->customer_due_date,
                'customer_qty_order'   => $enquiry->customer_qty_order,
                'me_ref'               => $enquiry->me_ref,
                'pending_qty'          => $enquiry->pending_qty,
                'customer_pdo_document'=> $enquiry->customer_pdo_document,


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

        return response()->json([
            'status'  => true,
            'message' => 'Customer PO List',
            'data'    => $data
        ], 200);

    } catch (\Exception $e) {

        return response()->json([
            'status'  => false,
            'message' => $e->getMessage()
        ], 500);
    }
}



public function update(Request $request, $id)
{
    DB::beginTransaction();

    try {

        $validated = $request->validate([
            'customer_po_no'        => 'required|string|max:255',
            'customer_due_date'     => 'required|date',
            'customer_qty_order'    => 'required|numeric|min:1',
            'me_ref'                => 'required|string|max:255',
            'pending_qty'           => 'nullable|numeric|min:0',
            'status'                => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
            'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ]);

        $inquiry = Inquiry::findOrFail($id);

        $parentId = $inquiry->parent_id;

        // Upload file if provided
        $document = $inquiry->customer_pdo_document;

        if ($request->hasFile('customer_pdo_document')) {

            $file = $request->file('customer_pdo_document');

            $document = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();

            $file->move(public_path('uploads/customer_po_documents'), $document);
        }

        Inquiry::where('parent_id', $parentId)->update([
            'customer_po_no'         => $validated['customer_po_no'],
            'customer_due_date'      => $validated['customer_due_date'],
            'customer_qty_order'     => $validated['customer_qty_order'],
            'me_ref'                 => $validated['me_ref'],
            'pending_qty'            => $validated['pending_qty'] ?? null,
            'customer_pdo_document'  => $document,
            'status'                 => $validated['status'],
            'updated_by'             => Auth::id(),
            'updated_at'             => now(),
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Customer PO updated successfully.'
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

        $request->validate([
           
            'inquiry_id'            => 'required|string|max:20',
            'inquiry_code'          => 'required|string|max:20',
            'customer_id'           => 'required|exists:users,id',

            'customer_po_no'        => 'required|string|max:255',
            'customer_due_date'     => 'required|date',
            'customer_qty_order'    => 'required|numeric|min:1',
            'me_ref'                => 'required|string|max:255',
            'pending_qty'           => 'nullable|numeric|min:0',

            'status' => 'required|in:Price Requested,Pending,Closed,Completed,Regret',

            'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ]);

        // Parent Inquiry
        

        // Duplicate Inquiry ID
        if (Inquiry::where('inquiry_id', $request->inquiry_id)->exists()) {

            return response()->json([
                'status' => false,
                'message' => 'Inquiry ID already exists.'
            ], 422);
        }

        // Duplicate Inquiry Code
        if (Inquiry::where('inquiry_code', $request->inquiry_code)->exists()) {

            return response()->json([
                'status' => false,
                'message' => 'Inquiry Code already exists.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Upload Customer PO Document
        |--------------------------------------------------------------------------
        */

        $document = null;

        if ($request->hasFile('customer_pdo_document')) {

            $file = $request->file('customer_pdo_document');

            $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

            $file->move(public_path('uploads/customer_po_documents'), $document);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Record
        |--------------------------------------------------------------------------
        */

        $inquiry = Inquiry::create([

            'parent_id'             => 0,

            'inquiry_code'          => $request->inquiry_code,
            'inquiry_id'            => $request->inquiry_id,

            'enquiry_type'          => 'Customer_PO',

            // Copy from Parent Inquiry
            'customer_id'           => $request->customer_id,
          

            'status'                => $request->status,

            'customer_po_no'        => $request->customer_po_no,
            'customer_due_date'     => $request->customer_due_date,
            'customer_qty_order'    => $request->customer_qty_order,
            'me_ref'                => $request->me_ref,
            'pending_qty'           => $request->pending_qty,
            'customer_pdo_document' => $document,

            'user_id'               => Auth::id(),
            'created_by'            => Auth::id(),
            'updated_by'            => Auth::id(),
        ]);

        // First record becomes parent
        $inquiry->update([
            'parent_id' => $inquiry->id
        ]);

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Customer PO created successfully.',
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