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

class PoToPrincipalController extends Controller
{
    public function convertTopoprincipal(Request $request, $id)
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
                'po_principal_no'              => 'nullable|string|max:255',
                
                'total_qty_ordered_principal'  => 'nullable|numeric|min:1',
                'qty_received_principal'       => 'nullable|numeric|min:0',
                'released_date'                => 'nullable|date',
               
                'principal_po_document'        => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
                'item_ids'                     => 'nullable|array',
                'item_ids.*'                   => 'integer|exists:inquiries,id',
            ]);

            $document = null;

            if ($request->hasFile('principal_po_document')) {

                $file = $request->file('principal_po_document');

                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('uploads/principal_po_documents'), $document);
            }

            $totalQtyOrdered = $validated['total_qty_ordered_principal'];
            $qtyReceived     = $validated['qty_received_principal'] ?? 0;
            $pendingQty      = max(0, $totalQtyOrdered - $qtyReceived);

            // Only the items actually selected for this conversion — falls
            // back to just the clicked row ($id) if the frontend didn't
            // send item_ids, instead of touching every row that happens to
            // share this enquiry's inquiry_code.
            $itemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$id];

            Inquiry::whereIn('id', $itemIds)
                ->update([
                    'enquiry_type'                 => 'PO_Principal',
                    'po_principal_no'              => $validated['po_principal_no'],
                   
                    'total_qty_ordered_principal'  => $totalQtyOrdered,
                    'qty_received_principal'       => $qtyReceived,
                    'pending_qty_principal'        => $pendingQty,
                    'released_date'                => $validated['released_date'] ?? null,
                    'principal_po_document'        => $document,
                    
                    'updated_by'                   => Auth::id(),
                    'updated_at'                   => now(),
                ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'PO to Principal created successfully.',
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

            Log::error('PO to Principal Error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

      public function poprincipalList()
    {
        try {

          // AFTER
$stages = ['PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

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

            // foreach ($enquiries as $enquiry) {

            //     $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
            //     ->where('parent_id', $enquiry->id)
            //     ->whereIn('enquiry_type', $stages)
            //     ->get();

            //     $data[] = [
            //         'id'                           => $enquiry->id,
            //         'parent_id'                    => $enquiry->parent_id,
            //         'inquiry_id'                   => $enquiry->inquiry_id,
            //         'inquiry_code'                 => $enquiry->inquiry_code,
            //         'enquiry_type'                 => $enquiry->enquiry_type,
            //         'customer_id'                  => $enquiry->customer_id,
            //         'customer_name'                => optional($enquiry->customer)->name,
            //         'principal_id'                 => $enquiry->principal_id,
            //         'me_ref'                       => $enquiry->me_ref,
            //         'customer_po_no'               => $enquiry->customer_po_no,
            //         'customer_due_date'            => $enquiry->customer_due_date,
            //         'customer_qty_order'           => $enquiry->customer_qty_order,
            //         'pending_qty'                  => $enquiry->pending_qty,
            //         'po_principal_no'              => $enquiry->po_principal_no,
            //         'promise_date'                 => $enquiry->promise_date,
            //         'total_qty_ordered_principal'  => $enquiry->total_qty_ordered_principal,
            //         'qty_received_principal'       => $enquiry->qty_received_principal,
            //         'pending_qty_principal'        => $enquiry->pending_qty_principal,
            //         'released_date'                => $enquiry->released_date,
            //         'principal_po_document'        => $enquiry->principal_po_document,
            //         'status'          => $enquiry->status,

           foreach ($enquiries as $enquiry) {

    $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
        ->where('parent_id', $enquiry->id)
        ->whereIn('enquiry_type', $stages)
        ->get();

    if ($items->isEmpty()) {
        continue;
    }

    // Group by po_principal_no so each separate conversion gets its own row.
    $batches = $items->groupBy(function ($row) {
        return $row->po_principal_no;
    });

    foreach ($batches as $batchItems) {

        $sourceRow = $batchItems->first();

        $data[] = [
            'id'                           => $sourceRow->id,
            'parent_id'                    => $enquiry->id,
            'inquiry_id'                   => $enquiry->inquiry_id,
            'inquiry_code'                 => $enquiry->inquiry_code,
            'enquiry_type'                 => $sourceRow->enquiry_type,
            'customer_id'                  => $enquiry->customer_id,
            'customer_name'                => optional($enquiry->customer)->name,
            'principal_id'                 => $sourceRow->principal_id,
            'me_ref'                       => $enquiry->me_ref,
            'customer_po_no'               => $enquiry->customer_po_no,
            'customer_due_date'            => $enquiry->customer_due_date,
            'customer_qty_order'           => $enquiry->customer_qty_order,
            'pending_qty'                  => $enquiry->pending_qty,
            'po_principal_no'              => $sourceRow->po_principal_no,
            'promise_date'                 => $sourceRow->promise_date,
            'total_qty_ordered_principal'  => $sourceRow->total_qty_ordered_principal,
            'qty_received_principal'       => $sourceRow->qty_received_principal,
            'pending_qty_principal'        => $sourceRow->pending_qty_principal,
            'released_date'                => $sourceRow->released_date,
            'principal_po_document'        => $sourceRow->principal_po_document,
            'status'                       => $sourceRow->status,

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
                    'isConverted'   => $row->enquiry_type !== 'PO_Principal',
                ];
            })->values(),
        ];
    }
}
            return response()->json([
                'status'  => true,
                'message' => 'PO to Principal List',
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
                  ->orWhere('po_principal_no', 'like', "%{$req->search}%")
                  ->orWhere('me_ref', 'like', "%{$req->search}%")
                  ->orWhere('po_principal_status', 'like', "%{$req->search}%");
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
        $allowedSorts = ['id', 'promise_date', 'po_principal_status', 'created_at'];

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
                'po_principal_no'              => 'required|string|max:255',
                'promise_date'                 => 'required|date',
                'total_qty_ordered_principal'  => 'required|numeric|min:1',
                'qty_received_principal'       => 'nullable|numeric|min:0',
                'released_date'                => 'nullable|date',
                'po_principal_status'          => 'required|in:PENDING,DISPATCH PARTIAL,DISPATCH FULL',
                'principal_po_document'        => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
                'item_ids'                     => 'nullable|array',
                'item_ids.*'                   => 'integer|exists:inquiries,id',
            ]);

            $inquiry = Inquiry::findOrFail($id);

            $document = $inquiry->principal_po_document;

            if ($request->hasFile('principal_po_document')) {

                $file = $request->file('principal_po_document');

                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('uploads/principal_po_documents'), $document);
            }

            $totalQtyOrdered = $validated['total_qty_ordered_principal'];
            $qtyReceived     = $validated['qty_received_principal'] ?? 0;
            $pendingQty      = max(0, $totalQtyOrdered - $qtyReceived);

            // Same fix as convertTopoprincipal(): scope to the items that
            // actually belong to this PO-to-Principal batch, not every row
            // under the enquiry's parent_id.
            $itemIds = !empty($validated['item_ids']) ? $validated['item_ids'] : [$id];

            Inquiry::whereIn('id', $itemIds)->update([
                'po_principal_no'              => $validated['po_principal_no'],
                'promise_date'                 => $validated['promise_date'],
                'total_qty_ordered_principal'  => $totalQtyOrdered,
                'qty_received_principal'       => $qtyReceived,
                'pending_qty_principal'        => $pendingQty,
                'released_date'                => $validated['released_date'] ?? null,
                'principal_po_document'        => $document,
                'po_principal_status'          => $validated['po_principal_status'],
                'updated_by'                   => Auth::id(),
                'updated_at'                   => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'PO to Principal updated successfully.'
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

            $validated = $request->validate([

                'inquiry_id'   => 'required|string|max:20',
                'inquiry_code' => 'required|string|max:20',

                'principal_id' => 'required|exists:users,id',

                'po_principal_no'               => 'required|string|max:255',
                'promise_date'                  => 'required|date',
                'total_qty_ordered_principal'   => 'required|numeric|min:1',
                'qty_received_principal'        => 'nullable|numeric|min:0',
                'released_date'                 => 'nullable|date',

                'po_principal_status' => 'required|in:Price Requested,Pending,Closed,Completed,Regret',

                'principal_po_document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Check Duplicate Inquiry ID
            |--------------------------------------------------------------------------
            */

            if (Inquiry::where('inquiry_id', $validated['inquiry_id'])->exists()) {
                return response()->json([
                    'po_principal_status' => false,
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
                    'po_principal_status' => false,
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

                'po_principal_status' => $validated['po_principal_status'],

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
                'po_principal_status' => true,
                'message' => 'Principal PO created successfully.',
                'data' => $inquiry
            ], 201);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'po_principal_status' => false,
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'po_principal_status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}