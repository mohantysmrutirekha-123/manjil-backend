<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CustomerQuoteController extends Controller
{

public function convertTocustomerQuotation(Request $request, $id)
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

        $validated = $request->validate([
            'offer_type'           => 'required|in:Quotation,Budgetary Offer',
            'quantity_quoted'      => 'required|numeric|min:1',
            'validity_period'      => 'required|string|max:100',
            'principal_quote_date' => 'required|date',
            'status'               => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
            'items'                => 'required|array|min:1',
            'items.*.id'           => 'required|exists:inquiries,id',
            'items.*.price'        => 'required|numeric|min:0'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Generate Quotation No / Budgetary No
        |--------------------------------------------------------------------------
        */

        $quotationNo = null;
        $budgetaryNo = null;

        if ($validated['offer_type'] == 'Quotation') {

            $lastQuotation = Inquiry::whereNotNull('quotation_no')
                ->orderByDesc('id')
                ->first();

            if ($lastQuotation && $lastQuotation->quotation_no) {

                $lastNo = (int) preg_replace('/[^0-9]/', '', $lastQuotation->quotation_no);

                $quotationNo = 'QN-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

            } else {

                $quotationNo = 'QN-000001';
            }

        } elseif ($validated['offer_type'] == 'Budgetary Offer') {

            $lastBudgetary = Inquiry::whereNotNull('budgetary_no')
                ->orderByDesc('id')
                ->first();

            if ($lastBudgetary && $lastBudgetary->budgetary_no) {

                $lastNo = (int) preg_replace('/[^0-9]/', '', $lastBudgetary->budgetary_no);

                $budgetaryNo = 'BO-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

            } else {

                $budgetaryNo = 'BO-000001';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Update Inquiry Items
        |--------------------------------------------------------------------------
        */

        foreach ($validated['items'] as $item) {

            Inquiry::where('id', $item['id'])
                ->where('inquiry_code', $firstInquiry->inquiry_code)
                ->update([
                    'enquiry_type'         => 'customer_quotation',
                    'offer_type'           => $validated['offer_type'],
                    'quotation_no'         => $quotationNo,
                    'budgetary_no'         => $budgetaryNo,
                    'quantity_quoted'      => $validated['quantity_quoted'],
                    'validity_period'      => $validated['validity_period'],
                    'principal_quote_date' => $validated['principal_quote_date'],
                    'price'                => $item['price'],
                    'status'               => $validated['status'],
                    'updated_by'           => Auth::id(),
                    'updated_at'           => now(),
                ]);
        }

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Customer quotation converted successfully.',
            'data' => [
                'quotation_no' => $quotationNo,
                'budgetary_no' => $budgetaryNo
            ]
        ], 200);

    } catch (ValidationException $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {

        DB::rollBack();

        Log::error($e->getMessage());

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}







public function customerquotationList()
{
    try {

        // Parent enquiries only
        $enquiries = Inquiry::with([
            'customer:id,name',
            'item:id,name,mat_code,part_model_no,description'
        ])
        ->whereColumn('id', 'parent_id')
        //   ->where('enquiry_type', 'customer_quotation')
        ->whereIn('enquiry_type', ['customer_quotation', 'Customer_PO', 'Negotiation','Customer_PO', 'PO_Principal'])
        ->latest()
        ->get();

        $data = [];

        foreach ($enquiries as $enquiry) {

            // Get all child items for this enquiry
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            //  ->where('enquiry_type', 'customer_quotation')
            ->whereIn('enquiry_type', ['customer_quotation', 'Customer_PO', 'Negotiation','Customer_PO', 'PO_Principal'])
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
                'quotation_no'       => $enquiry->quotation_no,
                'budgetary_no'       => $enquiry->budgetary_no,
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
            'message' => 'Customer Quotation List',
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
            'offer_type'   => $request->offer_type,
            'quantity_quoted'    => $request->quantity_quoted,
            'validity_period'    => $request->validity_period,
            'principal_quote_date' => $request->principal_quote_date,
          
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