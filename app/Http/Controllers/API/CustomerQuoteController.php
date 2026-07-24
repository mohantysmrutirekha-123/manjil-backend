<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

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

   public function customerquotationList(Request $req)
{
    try {

        $stages = ['customer_quotation', 'Customer_PO', 'Negotiation', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

        // Anchor rows only (id === parent_id) — one enquiry group each.
        $query = Inquiry::with([
            'customer:id,name',
            'item:id,name,mat_code,part_model_no,description'
        ])
        ->whereColumn('id', 'parent_id')
        ->where(function ($q) use ($stages) {
            $q->whereIn('enquiry_type', $stages)
              ->orWhereHas('children', function ($cq) use ($stages) {
                  $cq->whereIn('enquiry_type', $stages);
              });
        });

        $query = $this->applyFilters($query, $req);

        $enquiries = $query->get();

        $data = [];

        foreach ($enquiries as $enquiry) {

            // All converted items across every conversion batch for this enquiry.
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            ->whereIn('enquiry_type', $stages)
            ->get();

            // $batches = $items->groupBy(function ($row) {
            //     return $row->quotation_no . '|' . $row->budgetary_no . '|' . $row->customer_quote_date;
            // });

            // foreach ($batches as $batchItems) {

            $batches = $items->groupBy(function ($row) {
                return $row->quotation_no . '|' . $row->budgetary_no . '|' . $row->customer_quote_date;
            });

            // Stages that mean a batch has actually moved forward from a
            // plain, unconverted "customer_quotation" placeholder.
            $advancedStages = ['Customer_PO', 'Negotiation', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

            $hasAdvancedBatch = $batches->contains(function ($batchItems) use ($advancedStages) {
                return in_array($batchItems->first()->enquiry_type, $advancedStages);
            });

            if ($hasAdvancedBatch) {
                // Drop sibling batches still stuck at "customer_quotation"
                // with no quotation/budgetary number — these are stale
                // placeholders left behind once another batch converted.
                $batches = $batches->reject(function ($batchItems) use ($advancedStages) {
                    $first = $batchItems->first();
                    $isUnconverted = !in_array($first->enquiry_type, $advancedStages);
                    $isBlank = empty($first->quotation_no) && empty($first->budgetary_no);
                    return $isUnconverted && $isBlank;
                });
            }

            foreach ($batches as $batchItems) {

                $first = $batchItems->first();

                $data[] = [
                    'id'                   => $first->id,
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

        // return response()->json([
        //     'status'  => true,
        //     'message' => 'Customer Quotation List',
        //     'data'    => $data
        // ], 200);

                // Display-only dedupe: this page should show ONE row per Enquiry ID.
        // If the same inquiry_code produced multiple rows (e.g. a BO's
        // "Convert to Enquiry" spun off a second parent chain), keep the
        // row that actually has a Quotation/Budgetary number and drop the
        // blank placeholder(s). No data is changed — only what's rendered
        // on this list.
        $data = collect($data)
            ->groupBy('inquiry_code')
            ->map(function ($rows) {
                return $rows->sortByDesc(function ($row) {
                    return (!empty($row['quotation_no']) || !empty($row['budgetary_no'])) ? 1 : 0;
                })->first();
            })
            ->values()
            ->all();

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

    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
    private function applyFilters($query, Request $req)
    {
        // Search
        if ($req->search) {
            $query->where(function ($q) use ($req) {
                $q->where('enquiry_type', 'like', "%{$req->search}%")
                  ->orWhere('quotation_no', 'like', "%{$req->search}%")
                  ->orWhere('budgetary_no', 'like', "%{$req->search}%")
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
        $allowedSorts = ['id', 'customer_quote_date', 'status', 'created_at'];

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
                'offer_type'   => $request->offer_type,
                'quantity_quoted'    => $request->quantity_quoted,
                'validity_period'    => $request->validity_period,
                'customer_quote_date' => $request->customer_quote_date,

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