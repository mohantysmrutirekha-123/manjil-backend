<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException; 

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
 
            $validated = $request->validate([
    'customer_po_no'        => 'nullable|string|max:255',
    'customer_due_date'     => 'nullable|date',
    'customer_qty_order'    => 'nullable|numeric',
    'me_ref'                => 'nullable|string|max:255',
    'pending_qty'           => 'nullable|numeric|min:0',
    'status'                => 'nullable|in:Checked,Not Checked',
    'customer_pdo_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
    'item_ids'               => 'required|array|min:1',
    'item_ids.*'             => 'required|exists:inquiries,id',
    'item_quantities'                => 'nullable|array',
    'item_quantities.*.id'           => 'required_with:item_quantities|exists:inquiries,id',
    'item_quantities.*.ordered_qty'  => 'required_with:item_quantities|numeric|min:0',
]);
 
            /*
            |--------------------------------------------------------------------------
            | Cap ordered qty at each item's remaining (unconverted) qty
            |--------------------------------------------------------------------------
            | 'quantity' is the original line qty from the enquiry. Anything
            | already sitting in customer_qty_order for that row was already
            | converted in a previous batch, so the ceiling for THIS batch is
            | (quantity - customer_qty_order already converted elsewhere) —
            | never the full original quantity again.
            */
 
            $selectedItems = Inquiry::whereIn('id', $validated['item_ids'])
                ->where('inquiry_code', $firstInquiry->inquiry_code)
                ->get(['id', 'quantity', 'customer_qty_order']);
 
            $overLimit = [];
 
            $orderedQtyById = [];
 
            if (!empty($validated['item_quantities'])) {
                foreach ($validated['item_quantities'] as $row) {
                    $orderedQtyById[$row['id']] = (float) $row['ordered_qty'];
                }
            }
 
            foreach ($selectedItems as $item) {
 
                $alreadyConverted = (float) ($item->customer_qty_order ?? 0);
                $remaining        = max(0, (float) $item->quantity - $alreadyConverted);
 
                $requestedQty = array_key_exists($item->id, $orderedQtyById)
                    ? $orderedQtyById[$item->id]
                    : (float) ($validated['customer_qty_order'] ?? 0);
 
                if ($requestedQty > $remaining) {
                    $overLimit[] = [
                        'id'        => $item->id,
                        'requested' => $requestedQty,
                        'remaining' => $remaining,
                    ];
                }
            }
 
            if (!empty($overLimit)) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'Ordered qty exceeds the remaining unconverted qty for one or more items.',
                    'errors'  => $overLimit,
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
            | Update Inquiry Items
            |--------------------------------------------------------------------------
            */
 
            Inquiry::whereIn('id', $validated['item_ids'])
            ->where('inquiry_code', $firstInquiry->inquiry_code)
            ->update([
                'enquiry_type'          => 'Customer_PO',
                'customer_po_no'        => $validated['customer_po_no'],
                'customer_due_date'     => $validated['customer_due_date'],
                'me_ref'                => $validated['me_ref'],
                'customer_pdo_document' => $document,
                'status'                => $validated['status'],
                'updated_by'            => Auth::id(),
                'updated_at'            => now(),
            ]);
 
 
            if (!empty($validated['item_quantities'])) {
    foreach ($validated['item_quantities'] as $row) {
        $item = $selectedItems->firstWhere('id', $row['id']);
        $newConverted = (float) ($item->customer_qty_order ?? 0) + (float) $row['ordered_qty'];
        $itemRemaining = max(0, (float) $item->quantity - $newConverted);
 
        Inquiry::where('id', $row['id'])
            ->where('inquiry_code', $firstInquiry->inquiry_code)
            ->update([
                'customer_qty_order' => $newConverted,
                'pending_qty'        => $itemRemaining,
            ]);
    }
} else {
    // fallback: old behavior if no per-item data sent — same item is
    // credited with the single value across every selected row, added
    // to whatever it already had converted.
    foreach ($selectedItems as $item) {
        $newConverted  = (float) ($item->customer_qty_order ?? 0) + (float) ($validated['customer_qty_order'] ?? 0);
        $itemRemaining = max(0, (float) $item->quantity - $newConverted);
 
        Inquiry::where('id', $item->id)
            ->where('inquiry_code', $firstInquiry->inquiry_code)
            ->update([
                'customer_qty_order' => $newConverted,
                'pending_qty'        => $itemRemaining,
            ]);
    }
}
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

        $stages = ['Customer_PO', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

        // Anchor rows only (id === parent_id) — one enquiry group each.
        //
        // NOTE: previously this only matched when the ANCHOR row itself
        // had moved to one of $stages. But convertTocustomerpo() only
        // updates the specific item(s) selected via item_ids — it never
        // touches the anchor row unless the anchor happens to be among
        // them. So converting a non-anchor item made the whole enquiry
        // silently vanish from this list even though the converted item
        // genuinely exists — same class of bug already fixed in
        // principalquotationList()/customerquotationList()/negotiationList().
        // orWhereHas('children') closes that gap.
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

            // All Customer-PO-stage-or-later items across every
            // conversion batch for this enquiry.
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            ->whereIn('enquiry_type', $stages)
            ->get();

            if ($items->isEmpty()) {
                continue;
            }

            // Items converted together in one convertTocustomerpo() call
            // get the same customer_po_no + customer_due_date written onto
            // them at once, so that pair identifies a batch. `status`
            // intentionally excluded — same reasoning as every other
            // stage: editing/converting one item's status shouldn't
            // fracture the batch it belongs to.
            $batches = $items->groupBy(function ($row) {
                return $row->customer_po_no . '|' . $row->customer_due_date;
            });

            foreach ($batches as $batchItems) {

                // Use an item that's ACTUALLY in this batch as the source
                // of truth for the row's fields — never the enquiry
                // anchor directly. customer_po_no/customer_due_date/
                // me_ref/status/etc. are only ever written onto the
                // converted items themselves, not onto the anchor row, so
                // reading them off $enquiry returned null/stale data
                // whenever the anchor wasn't among the converted items.
                $first = $batchItems->first();

                $data[] = [
                    'id'                    => $first->id, // an item in this batch — used for edit/update actions
                    'parent_id'             => $enquiry->id,
                    'inquiry_id'            => $enquiry->inquiry_id,
                    'inquiry_code'          => $enquiry->inquiry_code,
                    'enquiry_type'          => $first->enquiry_type,
                    'customer_id'           => $enquiry->customer_id,
                    'customer_name'         => optional($enquiry->customer)->name,
                    'principal_id'          => $first->principal_id,
                    'principal_quote_no'    => $first->principal_quote_no,
                    'principal_quote_date'  => $first->principal_quote_date,
                    'offer_type'            => $first->offer_type,
                    'quantity_quoted'       => $first->quantity_quoted,
                    'validity_period'       => $first->validity_period,
                    'negotiation_details'   => $first->negotiation_details,
                    'follow_notes'          => $first->follow_notes,
                    'discussion_dates'      => $first->discussion_dates,
                    'revised_value'         => $first->revised_value,
                    'price'                 => $batchItems->sum('price'),
                    'status'                => $first->status,
                    'quotation_no'          => $first->quotation_no,
                    'budgetary_no'          => $first->budgetary_no,
                    'customer_po_no'        => $first->customer_po_no,
                    'customer_due_date'     => $first->customer_due_date,
                    'customer_qty_order'    => $first->customer_qty_order,
                    'me_ref'                => $first->me_ref,
                    'pending_qty'           => $first->pending_qty,
                    'customer_pdo_document' => $first->customer_pdo_document,


                    'items' => $batchItems->map(function ($row) {

                        return [
                            'id'            => $row->id,
                            'item_id'       => $row->item_id,
                            'material_code' => optional($row->item)->mat_code,
                            'item_name'     => optional($row->item)->name,
                            'description'   => optional($row->item)->description,
                            'part_model_no' => optional($row->item)->part_model_no,
                            'customer_qty_order' => $row->customer_qty_order,  
                            'quantity'      => $row->quantity,
                            'price'         => $row->price,
                            'status'        => $row->status,
                            'date'          => optional($row->created_at)->format('Y-m-d'),
                            'isConverted'   => $row->enquiry_type !== 'Customer_PO',
                        ];

                    })->values()
                ];
            }
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

    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
    private function applyFilters($query, Request $req)
    {
        // Search
        if ($req->search) {
            $query->where(function ($q) use ($req) {
                $q->where('enquiry_type', 'like', "%{$req->search}%")
                  ->orWhere('customer_po_no', 'like', "%{$req->search}%")
                  ->orWhere('me_ref', 'like', "%{$req->search}%")
                  ->orWhere('status', 'like', "%{$req->search}%");
            });
        }

        // Date filter — manual range wins if both given
        if ($req->from_date && $req->to_date) {
            $query->whereBetween('created_at', [
                $req->from_date . ' 00:00:00',
                $req->to_date . ' 23:59:59'
            ]);
        } elseif ($req->period) {
            [$start, $end] = $this->resolvePeriodRange($req->period);

            if ($start && $end) {
                $query->whereBetween('created_at', [$start, $end]);
            }
        }

        // Sorting
        $allowedSorts = ['id', 'customer_due_date', 'status', 'created_at'];

        $sortBy = in_array($req->sort_by, $allowedSorts) ? $req->sort_by : 'created_at';
        $sortOrder = $req->sort_order === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    /**
     * Resolve a named period ('weekly','monthly','quarterly','yearly')
     * into a [start, end] datetime string pair, anchored to "now".
     */
    private function resolvePeriodRange(string $period): array
    {
        $now = Carbon::now();

        switch (strtolower($period)) {
            case 'weekly':
                $start = $now->copy()->startOfWeek();
                $end   = $now->copy()->endOfWeek();
                break;

            case 'monthly':
                $start = $now->copy()->startOfMonth();
                $end   = $now->copy()->endOfMonth();
                break;

            case 'quarterly':
                $start = $now->copy()->firstOfQuarter();
                $end   = $now->copy()->lastOfQuarter();
                break;

            case 'yearly':
                $start = $now->copy()->startOfYear();
                $end   = $now->copy()->endOfYear();
                break;

            default:
                return [null, null];
        }

        return [
            $start->format('Y-m-d 00:00:00'),
            $end->format('Y-m-d 23:59:59'),
        ];
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
                'item_quantities.*.ordered_qty' => 'required_with:item_quantities|numeric|min:0',
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


            if (!empty($validated['item_quantities'])) {
    foreach ($validated['item_quantities'] as $row) {
        Inquiry::where('id', $row['id'])
            ->where('parent_id', $parentId)
            ->update(['customer_qty_order' => $row['ordered_qty']]);
    }
} else {
    Inquiry::where('parent_id', $parentId)
        ->update(['customer_qty_order' => $validated['customer_qty_order']]);
}

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