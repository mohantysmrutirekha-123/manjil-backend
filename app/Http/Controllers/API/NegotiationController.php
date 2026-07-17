<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NegotiationController extends Controller
{



public function convertTonegotiation(Request $request, $id)
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
            'negotiation_details' => 'required|string|max:255',
            'follow_notes'        => 'required|string|max:255',
            'discussion_dates'    => 'required|date',
            'status'              => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
            'revised_value'       => 'nullable|numeric|min:0',

            'items'               => 'required|array|min:1',
            'items.*.id'          => 'required|exists:inquiries,id',
            'items.*.price'       => 'required|numeric|min:0'
        ]);

        foreach ($validated['items'] as $item) {

            Inquiry::where('id', $item['id'])
                ->where('inquiry_code', $firstInquiry->inquiry_code)
                ->update([
                    'enquiry_type'        => 'Negotiation',
                    'negotiation_details' => $validated['negotiation_details'],
                    'follow_notes'        => $validated['follow_notes'],
                    'discussion_dates'    => $validated['discussion_dates'],
                    'revised_value'       => $validated['revised_value'] ?? null,
                    'price'               => $item['price'],
                    'status'              => $validated['status'],
                    'updated_by'          => Auth::id(),
                    'updated_at'          => now(),
                ]);
        }

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Negotiation converted successfully.'
        ], 200);

    } catch (ValidationException $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {

        DB::rollBack();

        Log::error('Negotiation Conversion Error: '.$e->getMessage());

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}














public function negotiationList()
{
    try {

        $stages = ['Negotiation', 'Customer_PO', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

        // Anchor rows only (id === parent_id) — one enquiry group each.
        //
        // NOTE: previously this only matched when the ANCHOR row itself
        // had moved to one of $stages. But convertTonegotiation() only
        // updates the specific item(s) the user selects — it never
        // touches the anchor row unless the anchor happens to be one of
        // the selected items. So converting a single non-anchor item
        // (e.g. item "c" while the anchor is still "d"/"a" sitting at an
        // earlier stage) made the whole enquiry silently vanish from this
        // list, even though the converted item genuinely exists. The
        // orWhereHas('children') clause (already used by
        // principalquotationList()/customerquotationList()) fixes this:
        // an enquiry shows up if EITHER the anchor OR any of its children
        // have reached one of these stages.
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

            // All negotiation-stage-or-later items across every conversion
            // batch for this enquiry.
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            ->whereIn('enquiry_type', $stages)
            ->get();

            if ($items->isEmpty()) {
                continue;
            }

            // Items converted together in one convertTonegotiation() call
            // get the same negotiation_details + discussion_dates written
            // onto them at once, so that pair identifies a batch — the
            // same way quotation_no/date identify a batch upstream.
            // `status` intentionally excluded, for the same reason as the
            // other stages: editing one item's status shouldn't fracture
            // the batch it belongs to.
            $batches = $items->groupBy(function ($row) {
                return $row->negotiation_details . '|' . $row->discussion_dates;
            });

            foreach ($batches as $batchItems) {

                // Use an item that's ACTUALLY in this batch as the source
                // of truth for the row's fields — never the enquiry
                // anchor directly. negotiation_details/follow_notes/
                // discussion_dates/revised_value/status/quotation_no are
                // only ever written onto the converted items themselves,
                // not onto the anchor row, so reading them off $enquiry
                // returned null/stale data whenever the anchor wasn't
                // among the converted items.
                $first = $batchItems->first();

                $data[] = [
                    'id'                   => $first->id, // an item in this batch — used for edit/update actions
                    'parent_id'            => $enquiry->id,
                    'inquiry_id'           => $enquiry->inquiry_id,
                    'inquiry_code'         => $enquiry->inquiry_code,
                    'enquiry_type'         => $first->enquiry_type,
                    'customer_id'          => $enquiry->customer_id,
                    'customer_name'        => optional($enquiry->customer)->name,
                    'principal_id'         => $first->principal_id,
                    'principal_quote_no'   => $first->principal_quote_no,
                    'principal_quote_date' => $first->principal_quote_date,
                    'offer_type'           => $first->offer_type,
                    'quantity_quoted'      => $first->quantity_quoted,
                    'validity_period'      => $first->validity_period,
                    'negotiation_details'  => $first->negotiation_details,
                    'follow_notes'         => $first->follow_notes,
                    'discussion_dates'     => $first->discussion_dates,
                    'revised_value'        => $first->revised_value,
                    'price'                => $batchItems->sum('price'),
                    'status'               => $first->status,
                    'quotation_no'         => $first->quotation_no,
                    'budgetary_no'         => $first->budgetary_no,

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
                            'isConverted'   => $row->enquiry_type !== 'Negotiation',
                        ];

                    })->values()
                ];
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Negotiation List',
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

        // Same scoping fix as PrincipalQuoteController/CustomerQuoteController:
        // don't blindly update everything sharing parent_id — that would
        // overwrite negotiation_details/status/etc. on unrelated batches
        // under the same enquiry too.
        //
        // Keyed on negotiation_details + discussion_dates to match
        // negotiationList()'s batch grouping exactly — items in a
        // negotiation batch don't necessarily share the same
        // quotation_no/budgetary_no (they may have arrived from
        // different upstream Customer Quotation batches), so that pair
        // is NOT a valid batch identity here the way it is upstream.
        $currentNegotiationDetails = $inquiry->negotiation_details;
        $currentDiscussionDates    = $inquiry->discussion_dates;

        Inquiry::where('parent_id', $parentId)
            ->where('negotiation_details', $currentNegotiationDetails)
            ->where('discussion_dates', $currentDiscussionDates)
            ->update([
            'negotiation_details'    => $request->negotiation_details,
            'follow_notes' => $request->follow_notes,
            'discussion_dates'    => $request->discussion_dates,
            'revised_value'       => $request->revised_value,

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
    DB::beginTransaction();

    try {

        $request->validate([
           
            'inquiry_id'           => 'required|string|max:20',
            'inquiry_code'         => 'required|string|max:20',

            'status'               => 'required',
            'offer_type'           => 'required|in:Quotation,Budgetary Offer',

            'negotiation_details'  => 'required|string',
            'follow_notes'         => 'required|string',
            'discussion_dates'     => 'required|date',
            'revised_value'        => 'nullable|numeric|min:0',
        ]);

        // Check Parent Inquiry
      

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
        | Generate Quotation/Budgetary Number
        |--------------------------------------------------------------------------
        */

        $quotationNo = null;
        $budgetaryNo = null;

        if ($request->offer_type == 'Quotation') {

            // Same fix as CustomerQuoteController: quotation_no is shared
            // across the whole inquiries table, so this MUST use the true
            // max numeric suffix rather than ->orderByDesc('id')->first().
            // Otherwise a number generated here can collide with one
            // already issued from the Customer Quotation stage.
            $lastNo = (int) Inquiry::whereNotNull('quotation_no')
                ->get(['quotation_no'])
                ->reduce(function ($max, $row) {
                    $num = (int) preg_replace('/[^0-9]/', '', $row->quotation_no);
                    return max($max, $num);
                }, 0);

            $quotationNo = 'QN-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

        } else {

            $lastNo = (int) Inquiry::whereNotNull('budgetary_no')
                ->get(['budgetary_no'])
                ->reduce(function ($max, $row) {
                    $num = (int) preg_replace('/[^0-9]/', '', $row->budgetary_no);
                    return max($max, $num);
                }, 0);

            $budgetaryNo = 'BO-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);
        }

        // Insert Single Record
        $inquiry = Inquiry::create([

            'parent_id'            => 0,

            'inquiry_code'         => $request->inquiry_code,
            'inquiry_id'           => $request->inquiry_id,

            'enquiry_type'         => 'Negotiation',

            // Copy from Parent Inquiry
          

            'status'               => $request->status,

            'offer_type'           => $request->offer_type,
            'negotiation_details'  => $request->negotiation_details,
            'follow_notes'         => $request->follow_notes,
            'discussion_dates'     => $request->discussion_dates,
            'revised_value'        => $request->revised_value,

            'quotation_no'         => $quotationNo,
            'budgetary_no'         => $budgetaryNo,

            'user_id'              => Auth::id(),
            'created_by'           => Auth::id(),
            'updated_by'           => Auth::id(),
        ]);

        // Set parent_id = own id
        $inquiry->update([
            'parent_id' => $inquiry->id
        ]);

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Negotiation created successfully.',
            'quotation_no' => $quotationNo,
            'budgetary_no' => $budgetaryNo,
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