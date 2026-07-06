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
        ->whereIn('enquiry_type', ['principal_quotation', 'customer_quotation', 'Customer_PO', 'Negotiation','Customer_PO', 'PO_Principal'])
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
            ->whereIn('enquiry_type', ['principal_quotation', 'customer_quotation', 'Customer_PO', 'Negotiation','Customer_PO', 'PO_Principal'])
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

  

}