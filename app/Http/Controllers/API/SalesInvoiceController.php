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

class SalesInvoiceController extends Controller
{
   public function convertTosalesinvoice(Request $request, $id)
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
            'item_ids'            => 'required|array|min:1',
            'item_ids.*'          => 'integer|exists:inquiries,id',
            'sales_invoice_no'    => 'required|string|max:255',
            'sales_invoice_date'  => 'required|date',
            'gst_percent'         => 'nullable|numeric|min:0',
            'qty_billed'          => 'required|numeric|min:1',
            'total_selling_value' => 'required|numeric|min:0',
        ]);

        $qtyOrderedTotal = (int) $firstInquiry->customer_qty_order;
        $pendingAfterBill = max(0, $qtyOrderedTotal - $validated['qty_billed']);

        Inquiry::where('inquiry_code', $firstInquiry->inquiry_code)
            ->whereIn('id', $validated['item_ids'])
            ->update([
                'enquiry_type'           => 'Sales_Invoice',
                'sales_invoice_no'       => $validated['sales_invoice_no'],
                'sales_invoice_date'     => $validated['sales_invoice_date'],
                'gst_percent'            => $validated['gst_percent'] ?? 0,
                'qty_billed'             => $validated['qty_billed'],
                'total_selling_value'    => $validated['total_selling_value'],
                'pending_qty_after_bill' => $pendingAfterBill,
                'updated_by'             => Auth::id(),
                'updated_at'             => now(),
            ]);

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Sales Invoice created successfully.',
        ], 200);

    } catch (ValidationException $e) {
        DB::rollBack();
        return response()->json(['status' => false, 'errors' => $e->errors()], 422);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Sales Invoice Error: ' . $e->getMessage());
        return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
    }
}

    public function sellinvoiceList()
    {
        try {

            $stages = ['Sales_Invoice'];

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

    // Group by sales_invoice_no so each separate conversion gets its own row.
    $batches = $items->groupBy(function ($row) {
        return $row->sales_invoice_no;
    });

    foreach ($batches as $batchItems) {

        $first = $batchItems->first();

        $data[] = [
            'id'                     => $first->id,
            'parent_id'              => $enquiry->id,
            'inquiry_id'             => $enquiry->inquiry_id,
            'inquiry_code'           => $enquiry->inquiry_code,
            'enquiry_type'           => $first->enquiry_type,
            'customer_id'            => $enquiry->customer_id,
            'customer_name'          => optional($enquiry->customer)->name,
            'principal_id'           => $enquiry->principal_id,
            'purchase_invoice_no'    => $first->purchase_invoice_no,
            'sales_invoice_no'       => $first->sales_invoice_no,
            'sales_invoice_date'     => $first->sales_invoice_date,
            'gst_percent'            => $first->gst_percent,
            'qty_billed'             => $first->qty_billed,
            'total_selling_value'    => $batchItems->sum('total_selling_value'),
            'pending_qty_after_bill' => $first->pending_qty_after_bill,
            'status'                 => $first->status,

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
                    'isConverted'   => $row->enquiry_type !== 'Sales_Invoice',
                ];
            })->values(),
        ];
    }
}

            return response()->json(['status' => true, 'message' => 'Sales Invoice List', 'data' => $data], 200);

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
                  ->orWhere('sales_invoice_no', 'like', "%{$req->search}%")
                  ->orWhere('sales_invoice_status', 'like', "%{$req->search}%");
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
        $allowedSorts = ['id', 'sales_invoice_date', 'sales_invoice_status', 'created_at'];

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
                'sales_invoice_no'     => 'required|string|max:255',
                'sales_invoice_date'   => 'required|date',
                'gst_percent'          => 'nullable|numeric|min:0',
                'qty_billed'           => 'required|numeric|min:1',
                'total_selling_value'  => 'required|numeric|min:0',
                'sales_invoice_status' => 'required|in:Generated,Issued,Paid',
            ]);

            $inquiry = Inquiry::findOrFail($id);
            $parentId = $inquiry->parent_id;

            $qtyOrderedTotal = (int) $inquiry->customer_qty_order;
            $pendingAfterBill = max(0, $qtyOrderedTotal - $validated['qty_billed']);

            Inquiry::where('parent_id', $parentId)->update([
                'sales_invoice_no'       => $validated['sales_invoice_no'],
                'sales_invoice_date'     => $validated['sales_invoice_date'],
                'gst_percent'            => $validated['gst_percent'] ?? 0,
                'qty_billed'             => $validated['qty_billed'],
                'total_selling_value'    => $validated['total_selling_value'],
                'pending_qty_after_bill' => $pendingAfterBill,
                'sales_invoice_status'   => $validated['sales_invoice_status'],
                'updated_by'             => Auth::id(),
                'updated_at'             => now(),
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Sales Invoice updated successfully.'], 200);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}