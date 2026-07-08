<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrincipalQuoteController extends Controller
{
 public function principalquotationList()
{
    try {

        // Parent enquiries only
        $enquiries = Inquiry::with([
            'customer:id,name',
            'item:id,name,mat_code,part_model_no,description'
        ])
        ->whereColumn('id', 'parent_id')
        //   ->where('enquiry_type', 'principal_quotation')
        ->whereIn('enquiry_type', ['principal_quotation', 'customer_quotation', 'Customer_PO', 'Negotiation','Customer_PO', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'])
        ->latest()
        ->get();

        $data = [];

        foreach ($enquiries as $enquiry) {

            // Get all child items for this enquiry
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            //  ->where('enquiry_type', 'principal_quotation')
            ->whereIn('enquiry_type', ['principal_quotation', 'customer_quotation', 'Customer_PO', 'Negotiation','Customer_PO', 'PO_Principal','Sales_Invoice', 'Purchase_Invoice'])
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
                'price'                => $enquiry->price,
                'status'               => $enquiry->status,

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
            'message' => 'Principal Quotation List',
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

        $inquiry = Inquiry::findOrFail($id);

        $parentId = $inquiry->parent_id;

        Inquiry::where('parent_id', $parentId)->update([
            'principal_quote_no'   => $request->principal_quote_no,
            'principal_quote_date' => $request->principal_quote_date,
            'customer_id'          => $request->customer_id,
            'principal_id'         => $request->principal_id,
            'status'               => $request->status,
            'updated_by'           => Auth::id(),
        ]);

        foreach ($request->items as $item) {

            Inquiry::where('id', $item['id'])->update([
                'price' => $item['price'],
                'updated_by' => Auth::id(),
            ]);
        }

        DB::commit();

        return response()->json([
            'success' => true
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'error' => $e->getMessage()
        ],500);
    }
}




public function store(Request $request)
{
    try {

        $request->validate([
           
            'inquiry_id'             => 'required|string|max:20',
            'inquiry_code'           => 'required|string|max:20',
            'principal_quote_no'     => 'required|integer',
            'principal_quote_date'   => 'required|date',
            'customer_id'            => 'required|exists:users,id',
            'principal_id'           => 'required|exists:users,id',
            'status'                 => 'required',
           

            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'required|exists:items,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.price'          => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        // Get original inquiry
        
        // Check duplicate Inquiry ID
        if (Inquiry::where('inquiry_id', $request->inquiry_id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Inquiry ID already exists.'
            ], 422);
        }

        // Check duplicate Inquiry Code
        if (Inquiry::where('inquiry_code', $request->inquiry_code)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Inquiry Code already exists.'
            ], 422);
        }

        $newParentId = null;

        foreach ($request->items as $index => $item) {

            $inquiry = Inquiry::create([

                'parent_id'             => 0,

                'inquiry_code'          => $request->inquiry_code,
                'inquiry_id'            => $request->inquiry_id,

                'enquiry_type'          => 'principal_quotation',

                'customer_id'           => $request->customer_id,

                'item_id'               => $item['item_id'],

               

                'quantity'              => $item['quantity'],

                'principal_id'          => $request->principal_id,

                'principal_quote_no'    => $request->principal_quote_no,
                'principal_quote_date'  => $request->principal_quote_date,

                'price'                 => $item['price'],

                'status'                => $request->status,

                'user_id'               => Auth::id(),
                'created_by'            => Auth::id(),
                'updated_by'            => Auth::id(),
            ]);

            if ($index == 0) {

                $newParentId = $inquiry->id;

                $inquiry->update([
                    'parent_id' => $newParentId
                ]);

            } else {

                $inquiry->update([
                    'parent_id' => $newParentId
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Principal quotation created successfully.'
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