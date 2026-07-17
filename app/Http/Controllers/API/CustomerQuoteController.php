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

// public function convertTocustomerQuotation(Request $request, $id)
// {
//     DB::beginTransaction();

//     try {

//         $firstInquiry = Inquiry::find($id);

//         if (!$firstInquiry) {
//             return response()->json([
//                 'status' => false,
//                 'message' => 'Inquiry not found.'
//             ], 404);
//         }

//         if ($firstInquiry->user_id != Auth::id() && Auth::user()->role != 'admin') {
//             return response()->json([
//                 'status' => false,
//                 'message' => 'Unauthorized.'
//             ], 403);
//         }

//         $validated = $request->validate([
//             'offer_type'           => 'required|in:Quotation,Budgetary Offer',
//             'quantity_quoted'      => 'required|numeric|min:1',
//             'validity_period'      => 'required|string|max:100',
//             'customer_quote_date'  => 'required|date',
//             'status'               => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
//             'items'                => 'required|array|min:1',
//             'items.*.id'           => 'required|exists:inquiries,id',
//             'items.*.price'        => 'required|numeric|min:0'
//         ]);

//         /*
//         |--------------------------------------------------------------------------
//         | Generate Quotation No / Budgetary No
//         |--------------------------------------------------------------------------
//         */

//         $quotationNo = null;
//         $budgetaryNo = null;

//         if ($validated['offer_type'] == 'Quotation') {

//             $lastQuotation = Inquiry::whereNotNull('quotation_no')
//                 ->orderByDesc('id')
//                 ->first();

//             if ($lastQuotation) {
//                 $lastNo = (int) preg_replace('/[^0-9]/', '', $lastQuotation->quotation_no);
//                 $quotationNo = 'QN-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);
//             } else {
//                 $quotationNo = 'QN-000001';
//             }

//         } else {

//             $lastBudgetary = Inquiry::whereNotNull('budgetary_no')
//                 ->orderByDesc('id')
//                 ->first();

//             if ($lastBudgetary) {
//                 $lastNo = (int) preg_replace('/[^0-9]/', '', $lastBudgetary->budgetary_no);
//                 $budgetaryNo = 'BO-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);
//             } else {
//                 $budgetaryNo = 'BO-000001';
//             }
//         }

//         /*
//         |--------------------------------------------------------------------------
//         | Parent ID Logic
//         |--------------------------------------------------------------------------
//         */

//         $selectedIds = collect($validated['items'])->pluck('id')->toArray();

//         $currentParentId = $firstInquiry->parent_id;

//        if (!empty($currentParentId)) {

//     $totalItems = Inquiry::where('parent_id', $currentParentId)->count();

//     $selectedCount = Inquiry::where('parent_id', $currentParentId)
//         ->whereIn('id', $selectedIds)
//         ->count();

//     if ($totalItems == $selectedCount) {

//         // All items selected -> keep same parent_id
//         $newParentId = $currentParentId;

//     } else {

//         // Partial items selected -> this batch needs its own anchor row.
//         // Use the id of the first selected item as the new parent_id,
//         // so that item's id === parent_id after the update below.
//         $newParentId = $selectedIds[0];
//     }

// } else {

//     // No parent_id exists -> same anchor logic
//     $newParentId = $selectedIds[0];
// }

//         /*
//         |--------------------------------------------------------------------------
//         | Update Selected Items
//         |--------------------------------------------------------------------------
//         */

//         foreach ($validated['items'] as $item) {

//             Inquiry::where('id', $item['id'])
//                 ->update([
//                     'parent_id'            => $newParentId,
//                     'enquiry_type'         => 'customer_quotation',
//                     'offer_type'           => $validated['offer_type'],
//                     'quotation_no'         => $quotationNo,
//                     'budgetary_no'         => $budgetaryNo,
//                     'quantity_quoted'      => $validated['quantity_quoted'],
//                     'validity_period'      => $validated['validity_period'],
//                     'customer_quote_date'  => $validated['customer_quote_date'],
//                     'price'                => $item['price'],
//                     'status'               => $validated['status'],
//                     'updated_by'           => Auth::id(),
//                     'updated_at'           => now(),
//                 ]);
//         }

//         DB::commit();

//         return response()->json([
//             'status' => true,
//             'message' => 'Customer quotation converted successfully.',
//             'data' => [
//                 'parent_id'     => $newParentId,
//                 'quotation_no'  => $quotationNo,
//                 'budgetary_no'  => $budgetaryNo
//             ]
//         ], 200);

//     } catch (ValidationException $e) {

//         DB::rollBack();

//         return response()->json([
//             'status' => false,
//             'errors' => $e->errors()
//         ], 422);

//     } catch (\Exception $e) {

//         DB::rollBack();

//         Log::error($e->getMessage());

//         return response()->json([
//             'status' => false,
//             'message' => $e->getMessage()
//         ], 500);
//     }
// }


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
            'customer_quote_date' => 'required|date',
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
                    'customer_quote_date' => $validated['customer_quote_date'],
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

        $stages = ['customer_quotation', 'Customer_PO', 'Negotiation', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

        // Anchor rows only (id === parent_id) — one enquiry group each.
        $enquiries = Inquiry::with([
            'customer:id,name',
            'item:id,name,mat_code,part_model_no,description'
        ])
        ->whereColumn('id', 'parent_id')
        ->where(function ($q) use ($stages) {
            $q->whereIn('enquiry_type', $stages)
              ->orWhereHas('children', function ($cq) use ($stages) {
                  $cq->whereIn('enquiry_type', $stages);
              });
        })
        ->latest()
        ->get();

        $data = [];

        foreach ($enquiries as $enquiry) {

            // All converted items across every conversion batch for this enquiry.
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            ->whereIn('enquiry_type', $stages)
            ->get();

            // Same batching caveat as principalquotationList(): there's no
            // dedicated batch_id column yet, so batches are distinguished by
            // this composite key. If two batches ever share ALL of these
            // values simultaneously, they will incorrectly merge into one
            // row. Add a real batch_id column to make this collision-proof.
     $batches = $items->groupBy(function ($row) {
    return $row->quotation_no . '|' . $row->budgetary_no . '|' . $row->customer_quote_date;
});

            foreach ($batches as $batchItems) {

                $first = $batchItems->first();

                $data[] = [
                    'id'                   => $first->id, // first item in this batch — used for edit/update actions
                    'parent_id'            => $enquiry->id,
                    'inquiry_id'           => $enquiry->inquiry_id,
                    'inquiry_code'         => $enquiry->inquiry_code,
                    'enquiry_type'         => $first->enquiry_type,
                    'customer_id'          => $enquiry->customer_id,
                    'customer_name'        => optional($enquiry->customer)->name,
                    'principal_id'         => $first->principal_id,
                    'principal_quote_no'   => $first->principal_quote_no,
                    'customer_quote_date'  => $first->customer_quote_date,
                    'offer_type'           => $first->offer_type,
                    'quantity_quoted'      => $first->quantity_quoted,
                    'validity_period'      => $first->validity_period,
                    'quotation_no'         => $first->quotation_no,
                    'budgetary_no'         => $first->budgetary_no,
                    'price'                => $batchItems->sum('price'),
                    'status'               => $first->status,

                    'items' => $batchItems->map(function ($row) {

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
                            'isConverted'   => $row->enquiry_type !== 'customer_quotation',
                        ];

                    })->values()
                ];
            }
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

        $parentId = $inquiry->parent_id ?: $inquiry->id;

        $updateData = [
            'offer_type'         => $request->offer_type,
            'quantity_quoted'    => $request->quantity_quoted,
            'validity_period'    => $request->validity_period,
            'customer_quote_date'=> $request->customer_quote_date,
            'status'             => $request->status,
            'updated_by'         => Auth::id(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Generate Quotation / Budgetary Number
        |--------------------------------------------------------------------------
        */

        if ($request->offer_type == 'Quotation') {

            // Generate only if quotation_no is empty
            if (empty($inquiry->quotation_no)) {

                $lastQuotation = Inquiry::whereNotNull('quotation_no')
                    ->orderByDesc('id')
                    ->first();

                if ($lastQuotation) {

                    $lastNo = (int) preg_replace('/\D/', '', $lastQuotation->quotation_no);
                    $quotationNo = 'QN-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

                } else {

                    $quotationNo = 'QN-000001';
                }

                $updateData['quotation_no'] = $quotationNo;
            }

            // Clear Budgetary No
            $updateData['budgetary_no'] = null;
        }

        elseif ($request->offer_type == 'Budgetary Offer') {

            // Generate only if budgetary_no is empty
            if (empty($inquiry->budgetary_no)) {

                $lastBudgetary = Inquiry::whereNotNull('budgetary_no')
                    ->orderByDesc('id')
                    ->first();

                if ($lastBudgetary) {

                    $lastNo = (int) preg_replace('/\D/', '', $lastBudgetary->budgetary_no);
                    $budgetaryNo = 'BO-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

                } else {

                    $budgetaryNo = 'BO-000001';
                }

                $updateData['budgetary_no'] = $budgetaryNo;
            }

            // Clear Quotation No
            $updateData['quotation_no'] = null;
        }

        Inquiry::where('parent_id', $parentId)->update($updateData);

        foreach ($request->items as $item) {

            Inquiry::where('id', $item['id'])
                ->where('parent_id', $parentId)
                ->update([
                    'price'      => $item['price'],
                    'updated_by' => Auth::id(),
                ]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Customer quotation updated successfully.'
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}







public function store(Request $request)
{
    DB::beginTransaction();

    try {

        $request->validate([
           
            'inquiry_id'           => 'required|string|max:20',
            'inquiry_code'         => 'required|string|max:20',

            'customer_quote_date'  => 'required|date',
            'customer_id'          => 'required|exists:users,id',

            'status'               => 'required',
            'offer_type'           => 'required|in:Quotation,Budgetary Offer',
            'quantity_quoted'      => 'required|numeric|min:1',
            'validity_period'      => 'required|string|max:100',
            'offered_value'        => 'required|numeric|min:0',

            'items'                => 'required|array|min:1',
            'items.*.item_id'      => 'required|exists:items,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.price'        => 'required|numeric|min:0',
        ]);

        // Parent Inquiry
        

        // Duplicate Inquiry ID
        if (Inquiry::where('inquiry_id', $request->inquiry_id)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Inquiry ID already exists.'
            ],422);
        }

        // Duplicate Inquiry Code
        if (Inquiry::where('inquiry_code', $request->inquiry_code)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Inquiry Code already exists.'
            ],422);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Quotation/Budgetary Number
        |--------------------------------------------------------------------------
        */

        $quotationNo = null;
        $budgetaryNo = null;

        if ($request->offer_type == 'Quotation') {

            $lastQuotation = Inquiry::whereNotNull('quotation_no')
                ->orderByDesc('id')
                ->first();

            if ($lastQuotation && !empty($lastQuotation->quotation_no)) {

                $lastNo = (int) preg_replace('/[^0-9]/', '', $lastQuotation->quotation_no);

                $quotationNo = 'QN-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

            } else {

                $quotationNo = 'QN-000001';
            }

        } else {

            $lastBudgetary = Inquiry::whereNotNull('budgetary_no')
                ->orderByDesc('id')
                ->first();

            if ($lastBudgetary && !empty($lastBudgetary->budgetary_no)) {

                $lastNo = (int) preg_replace('/[^0-9]/', '', $lastBudgetary->budgetary_no);

                $budgetaryNo = 'BO-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

            } else {

                $budgetaryNo = 'BO-000001';
            }
        }

        $newParentId = null;

        foreach ($request->items as $index => $item) {

            $inquiry = Inquiry::create([

                'parent_id'            => 0,

                'inquiry_code'         => $request->inquiry_code,
                'inquiry_id'           => $request->inquiry_id,

                'enquiry_type'         => 'customer_quotation',

                'customer_id'          => $request->customer_id,

                'item_id'              => $item['item_id'],

                // Copy from parent inquiry
                

                'quantity'             => $item['quantity'],
                'price'                => $item['price'],

                'status'               => $request->status,

                'customer_quote_date'  => $request->customer_quote_date,
                'offer_type'           => $request->offer_type,
                'quantity_quoted'      => $request->quantity_quoted,
                'validity_period'      => $request->validity_period,
                'offered_value'        => $request->offered_value,

                'quotation_no'         => $quotationNo,
                'budgetary_no'         => $budgetaryNo,

                'user_id'              => Auth::id(),
                'created_by'           => Auth::id(),
                'updated_by'           => Auth::id(),
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
            'message' => 'Customer quotation created successfully.',
            'quotation_no' => $quotationNo,
            'budgetary_no' => $budgetaryNo
        ],201);

    } catch (ValidationException $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'errors' => $e->errors()
        ],422);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ],500);
    }
}

}