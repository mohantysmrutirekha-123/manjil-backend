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

class PurchaseInvoiceController extends Controller
{
   public function convertTopurchaseinvoice(Request $request, $id)
{
    DB::beginTransaction();

    try {

        $firstInquiry = Inquiry::find($id);

        if (!$firstInquiry) {
            return response()->json(['status' => false, 'message' => 'Inquiry not found.'], 404);
        }

        if ($firstInquiry->user_id != Auth::id() && Auth::user()->role != 'admin') {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'item_ids'                 => 'required|array|min:1',
            'item_ids.*'               => 'integer|exists:inquiries,id',
            'purchase_invoice_no'     => 'required|string|max:255',
            'invoice_date'            => 'required|date',
            'unit_purchase_price'     => 'required|numeric|min:0',
            'qty_invoiced'            => 'required|numeric|min:1',
            'gst_tax_amount'          => 'nullable|numeric|min:0',
            'payment_due_date'        => 'required|date',
            'purchase_payment_status' => 'required|in:Pending,Partial,Paid',
        ]);

        $totalPurchaseValue = $validated['unit_purchase_price'] * $validated['qty_invoiced'];

        Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
            ->whereIn('id', $validated['item_ids']) // only the items chosen, not the whole group
            ->update([
                'enquiry_type'            => 'Purchase_Invoice',
                'purchase_invoice_no'     => $validated['purchase_invoice_no'],
                'invoice_date'            => $validated['invoice_date'],
                'unit_purchase_price'     => $validated['unit_purchase_price'],
                'qty_invoiced'            => $validated['qty_invoiced'],
                'gst_tax_amount'          => $validated['gst_tax_amount'] ?? 0,
                'total_purchase_value'    => $totalPurchaseValue,
                'payment_due_date'        => $validated['payment_due_date'],
                'purchase_payment_status' => $validated['purchase_payment_status'],
                'updated_by'              => Auth::id(),
                'updated_at'              => now(),
            ]);

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Purchase Invoice created successfully.',
        ], 200);

    } catch (ValidationException $e) {
        DB::rollBack();
        return response()->json(['status' => false, 'errors' => $e->errors()], 422);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Purchase Invoice Error: ' . $e->getMessage());
        return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
    }
}

    public function purchaseinvoiceList()
    {
        try {

           // AFTER
$stages = ['Purchase_Invoice', 'Sales_Invoice'];

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

    $items = Inquiry::with(['item:id,name,mat_code,part_model_no,description'])
        ->where('parent_id', $enquiry->id)
        ->whereIn('enquiry_type', $stages)
        ->get();

    if ($items->isEmpty()) {
        continue;
    }

    // Group by purchase_invoice_no so each separate conversion gets its own row.
    $batches = $items->groupBy(function ($row) {
        return $row->purchase_invoice_no;
    });

    foreach ($batches as $batchItems) {

        $sourceRow = $batchItems->first();

        $data[] = [
            'id'                       => $sourceRow->id,
            'parent_id'                => $enquiry->id,
            'inquiry_id'               => $enquiry->inquiry_id,
            'inquiry_code'             => $enquiry->inquiry_code,
            'enquiry_type'             => $sourceRow->enquiry_type,
            'customer_id'              => $enquiry->customer_id,
            'customer_name'            => optional($enquiry->customer)->name,
            'principal_id'             => $sourceRow->principal_id,
            'po_principal_no'          => $sourceRow->po_principal_no,
            'purchase_invoice_no'      => $sourceRow->purchase_invoice_no,
            'invoice_date'             => $sourceRow->invoice_date,
            'unit_purchase_price'      => $sourceRow->unit_purchase_price,
            'qty_invoiced'             => $sourceRow->qty_invoiced,
            'gst_tax_amount'           => $sourceRow->gst_tax_amount,
            'total_purchase_value'     => $sourceRow->total_purchase_value,
            'payment_due_date'         => $sourceRow->payment_due_date,
            'purchase_payment_status'  => $sourceRow->purchase_payment_status,
            'status'                   => $sourceRow->status,

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
                    'isConverted'   => $row->enquiry_type !== 'Purchase_Invoice',
                ];
            })->values(),
        ];
    }
}

            return response()->json(['status' => true, 'message' => 'Purchase Invoice List', 'data' => $data], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
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
                  ->orWhere('purchase_invoice_no', 'like', "%{$req->search}%")
                  ->orWhere('purchase_payment_status', 'like', "%{$req->search}%");
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
        $allowedSorts = ['id', 'invoice_date', 'payment_due_date', 'purchase_payment_status', 'created_at'];

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
            'item_ids'                 => 'required|array|min:1',
            'item_ids.*'               => 'integer|exists:inquiries,id',
            'purchase_invoice_no'     => 'required|string|max:255',
            'invoice_date'            => 'required|date',
            'unit_purchase_price'     => 'required|numeric|min:0',
            'qty_invoiced'            => 'required|numeric|min:1',
            'gst_tax_amount'          => 'nullable|numeric|min:0',
            'payment_due_date'        => 'required|date',
            'purchase_payment_status' => 'required|in:Pending,Partial,Paid',
        ]);

        $inquiry = Inquiry::findOrFail($id);
        $parentId = $inquiry->parent_id;

        $totalPurchaseValue = $validated['unit_purchase_price'] * $validated['qty_invoiced'];

        Inquiry::where('parent_id', $parentId)
            ->whereIn('id', $validated['item_ids']) // only the items chosen, not every sibling row
            ->update([
                'purchase_invoice_no'     => $validated['purchase_invoice_no'],
                'invoice_date'            => $validated['invoice_date'],
                'unit_purchase_price'     => $validated['unit_purchase_price'],
                'qty_invoiced'            => $validated['qty_invoiced'],
                'gst_tax_amount'          => $validated['gst_tax_amount'] ?? 0,
                'total_purchase_value'    => $totalPurchaseValue,
                'payment_due_date'        => $validated['payment_due_date'],
                'purchase_payment_status' => $validated['purchase_payment_status'],
                'updated_by'              => Auth::id(),
                'updated_at'              => now(),
            ]);

        DB::commit();

        return response()->json(['success' => true, 'message' => 'Purchase Invoice updated successfully.'], 200);

    } catch (ValidationException $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'errors' => $e->errors()], 422);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error($e->getMessage());
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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

                'purchase_invoice_no'               => 'required|string|max:255',
                'invoice_date'                  => 'required|date',
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