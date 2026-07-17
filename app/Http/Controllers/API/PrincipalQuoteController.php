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

        // Anchor rows only (id === parent_id) — one enquiry group each.
        $stages = ['principal_quotation', 'customer_quotation', 'Customer_PO', 'Negotiation', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

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
                'item:id,name,mat_code,part_model_no,description',
                 'sourceOpportunityItem:id,opportunity_code',
            ])
            ->where('parent_id', $enquiry->id)
            ->whereIn('enquiry_type', $stages)
            ->get();

            // Items are converted in batches — e.g. 2 of 6 items converted now
            // under one principal_quote_no, another 2 later under a different
            // one. There's no separate anchor row per batch (no new column,
            // no extra rows), so we split them back into batches here purely
            // by grouping on principal_quote_no + principal_quote_date. Each
            // group becomes its own row in the response, exactly like a
            // separate Principal Quotation entry, while still pointing at
            // the same enquiry.
            //
            // NOTE: `status` must NOT be part of this key. Converting one
            // item in a batch to a Customer Quotation changes that item's
            // `status` (and enquiry_type) while its siblings still awaiting
            // conversion keep their old status. If `status` were included
            // here, that single batch would fracture into two rows the
            // moment any one item moved forward — which is exactly the
            // "same enquiry/quote shows as 2 rows" bug.

            $batches = $items->groupBy(function ($row) {
                return $row->principal_quote_no . '|' . $row->principal_quote_date;
            });

            foreach ($batches as $batchItems) {

                // Prefer an item still awaiting conversion as the anchor —
                // its id/status drive the row's Edit action and STATUS
                // badge. Once every item in the batch has moved on, fall
                // back to the last item so the row shows "Completed".
                $first = $batchItems->firstWhere('enquiry_type', 'principal_quotation')
                    ?? $batchItems->first();

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
                    'principal_quote_date' => $first->principal_quote_date,
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

                        // NEW: tells frontend this item already moved to the next
                        // stage, so it should be shown (not hidden) with a
                        // "Converted" badge, and excluded from re-conversion.
                        'is_converted'  => $row->enquiry_type !== 'principal_quotation',
                        'enquiry_type'  => $row->enquiry_type,
                    ];

                })->values()
                ];
            }
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

        // Capture the batch's CURRENT identity before it gets overwritten,
        // so the update only touches this specific conversion batch —
        // not every batch sharing the same parent_id.
        $currentQuoteNo   = $inquiry->principal_quote_no;
        $currentQuoteDate = $inquiry->principal_quote_date;
        $currentStatus    = $inquiry->status;

        Inquiry::where('parent_id', $parentId)
            ->where('principal_quote_no', $currentQuoteNo)
            ->where('principal_quote_date', $currentQuoteDate)
            ->where('status', $currentStatus)
            ->update([
                'principal_quote_no'   => $request->principal_quote_no,
                'principal_quote_date' => $request->principal_quote_date,
                'customer_id'          => $request->customer_id,
                'principal_id'         => $request->principal_id,
                'status'               => $request->status,
                'updated_by'           => Auth::id(),
            ]);

        foreach ($request->items as $item) {

            Inquiry::where('id', $item['id'])
                ->where('parent_id', $parentId) // guard: item must belong to this enquiry
                ->update([
                    'price'      => $item['price'],
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
        ], 500);
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