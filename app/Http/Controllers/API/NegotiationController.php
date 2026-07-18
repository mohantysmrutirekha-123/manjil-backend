<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

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

    public function negotiationList(Request $req)
    {
        try {

            $query = Inquiry::with([
                'customer:id,name',
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->whereColumn('id', 'parent_id')
            ->whereIn('enquiry_type', ['Negotiation', 'Customer_PO','PO_Principal', 'Sales_Invoice', 'Purchase_Invoice']);

            $query = $this->applyFilters($query, $req);

            $enquiries = $query->get();

            $data = [];

            foreach ($enquiries as $enquiry) {

                // Get all child items for this enquiry
                $items = Inquiry::with([
                    'item:id,name,mat_code,part_model_no,description'
                ])
                ->where('parent_id', $enquiry->id)
                ->whereIn('enquiry_type', ['Negotiation', 'Customer_PO','PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'])
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
                    'negotiation_details' => $enquiry->negotiation_details,
                    'follow_notes'        => $enquiry->follow_notes,
                    'discussion_dates'    => $enquiry->discussion_dates,
                    'revised_value'       => $enquiry->revised_value,
                    'price'                => $enquiry->price,
                    'status'               => $enquiry->status,
                    'quotation_no'         => $enquiry->quotation_no,
                    'budgetary_no'          => $enquiry->budgetary_no,

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

    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
    private function applyFilters($query, Request $req)
    {
        // Search
        if ($req->search) {
            $query->where(function ($q) use ($req) {
                $q->where('enquiry_type', 'like', "%{$req->search}%")
                  ->orWhere('negotiation_details', 'like', "%{$req->search}%")
                  ->orWhere('follow_notes', 'like', "%{$req->search}%")
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
        $allowedSorts = ['id', 'discussion_dates', 'status', 'created_at'];

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

            $inquiry = Inquiry::findOrFail($id);

            $parentId = $inquiry->parent_id;

            Inquiry::where('parent_id', $parentId)->update([
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

                $lastQuotation = Inquiry::whereNotNull('quotation_no')
                    ->orderByDesc('id')
                    ->first();

                if ($lastQuotation && $lastQuotation->quotation_no) {

                    $lastNo = (int) preg_replace('/[^0-9]/', '', $lastQuotation->quotation_no);
                    $quotationNo = 'QN-' . str_pad($lastNo + 1, 6, '0', STR_PAD_LEFT);

                } else {

                    $quotationNo = 'QN-000001';
                }

            } else {

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

            // Insert Single Record
            $inquiry = Inquiry::create([

                'parent_id'            => 0,

                'inquiry_code'         => $request->inquiry_code,
                'inquiry_id'           => $request->inquiry_id,

                'enquiry_type'         => 'Negotiation',

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