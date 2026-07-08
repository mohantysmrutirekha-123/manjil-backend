<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
                'po_principal_no'              => 'required|string|max:255',
                'promise_date'                 => 'required|date',
                'total_qty_ordered_principal'  => 'required|numeric|min:1',
                'qty_received_principal'       => 'nullable|numeric|min:0',
                'released_date'                => 'nullable|date',
                'status'          => 'required|in:PENDING,DISPATCH PARTIAL,DISPATCH FULL',
                'principal_po_document'        => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
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

            Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
                ->update([
                    'enquiry_type'                 => 'PO_Principal',
                    'po_principal_no'              => $validated['po_principal_no'],
                    'promise_date'                 => $validated['promise_date'],
                    'total_qty_ordered_principal'  => $totalQtyOrdered,
                    'qty_received_principal'       => $qtyReceived,
                    'pending_qty_principal'        => $pendingQty,
                    'released_date'                => $validated['released_date'] ?? null,
                    'principal_po_document'        => $document,
                    'status'          => $validated['status'],
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

            $enquiries = Inquiry::with([
                'customer:id,name',
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->whereColumn('id', 'parent_id')
            ->whereIn('enquiry_type', ['PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'])
            ->latest()
            ->get();

            $data = [];

            foreach ($enquiries as $enquiry) {

                $items = Inquiry::with([
                    'item:id,name,mat_code,part_model_no,description'
                ])
                ->where('parent_id', $enquiry->id)
                ->whereIn('enquiry_type', ['PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'])
                ->get();

                $data[] = [
                    'id'                           => $enquiry->id,
                    'parent_id'                    => $enquiry->parent_id,
                    'inquiry_id'                   => $enquiry->inquiry_id,
                    'inquiry_code'                 => $enquiry->inquiry_code,
                    'enquiry_type'                 => $enquiry->enquiry_type,
                    'customer_id'                  => $enquiry->customer_id,
                    'customer_name'                => optional($enquiry->customer)->name,
                    'principal_id'                 => $enquiry->principal_id,
                    'me_ref'                       => $enquiry->me_ref,
                    'customer_po_no'               => $enquiry->customer_po_no,
                    'customer_due_date'            => $enquiry->customer_due_date,
                    'customer_qty_order'           => $enquiry->customer_qty_order,
                    'pending_qty'                  => $enquiry->pending_qty,
                    'po_principal_no'              => $enquiry->po_principal_no,
                    'promise_date'                 => $enquiry->promise_date,
                    'total_qty_ordered_principal'  => $enquiry->total_qty_ordered_principal,
                    'qty_received_principal'       => $enquiry->qty_received_principal,
                    'pending_qty_principal'        => $enquiry->pending_qty_principal,
                    'released_date'                => $enquiry->released_date,
                    'principal_po_document'        => $enquiry->principal_po_document,
                    'status'          => $enquiry->status,
                    'status'                       => $enquiry->status,

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
                'status'          => 'required|in:PENDING,DISPATCH PARTIAL,DISPATCH FULL',
                'principal_po_document'        => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
            ]);

            $inquiry = Inquiry::findOrFail($id);

            $parentId = $inquiry->parent_id;

            $document = $inquiry->principal_po_document;

            if ($request->hasFile('principal_po_document')) {

                $file = $request->file('principal_po_document');

                $document = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('uploads/principal_po_documents'), $document);
            }

            $totalQtyOrdered = $validated['total_qty_ordered_principal'];
            $qtyReceived     = $validated['qty_received_principal'] ?? 0;
            $pendingQty      = max(0, $totalQtyOrdered - $qtyReceived);

            Inquiry::where('parent_id', $parentId)->update([
                'po_principal_no'              => $validated['po_principal_no'],
                'promise_date'                 => $validated['promise_date'],
                'total_qty_ordered_principal'  => $totalQtyOrdered,
                'qty_received_principal'       => $qtyReceived,
                'pending_qty_principal'        => $pendingQty,
                'released_date'                => $validated['released_date'] ?? null,
                'principal_po_document'        => $document,
                'status'          => $validated['status'],
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