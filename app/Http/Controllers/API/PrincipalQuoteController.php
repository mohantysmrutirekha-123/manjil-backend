<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrincipalQuoteController extends Controller
{
    public function principalquotationList(Request $req)
{
    try {

        $stages = ['principal_quotation', 'customer_quotation', 'Customer_PO', 'Negotiation', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'];

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

            // Get all child items for this enquiry
            $items = Inquiry::with([
                'item:id,name,mat_code,part_model_no,description'
            ])
            ->where('parent_id', $enquiry->id)
            ->whereIn('enquiry_type', ['principal_quotation', 'customer_quotation', 'Customer_PO', 'Negotiation', 'PO_Principal', 'Sales_Invoice', 'Purchase_Invoice'])
            ->get();

            // The head/parent row's own principal_quote_no can be empty even
            // when a child item under it has already been quoted (e.g. rows
            // created via Opportunity -> Enquiry conversion, which doesn't
            // set principal_quote_no on the head). Fall back to the first
            // child that has one, same idea as price already falls back to
            // item rows on the frontend.
            $quotedItem = $items->first(fn ($row) => !empty($row->principal_quote_no));
            $quotedDateItem = $items->first(fn ($row) => !empty($row->principal_quote_date));
            $linkedItem = $items->first(fn ($row) => !empty($row->linked_customer_quote_id));
            // received_via has the same head-vs-child issue as principal_quote_no
            // above — the head row is often null while a child item under it
            // (created on conversion) actually carries the value.
            $viaItem = $items->first(fn ($row) => !empty($row->received_via));

            $data[] = [
                'id'                       => $enquiry->id,
                'parent_id'                => $enquiry->parent_id,
                'inquiry_id'               => $enquiry->inquiry_id,
                'inquiry_code'             => $enquiry->inquiry_code,
                'enquiry_type'             => $enquiry->enquiry_type,
                'customer_id'              => $enquiry->customer_id,
                'customer_name'            => optional($enquiry->customer)->name,
                'principal_id'             => $enquiry->principal_id,
                'received_via'             => $enquiry->received_via ?? optional($viaItem)->received_via,
                'principal_quote_no'       => $enquiry->principal_quote_no ?? optional($quotedItem)->principal_quote_no,
                'principal_quote_date'     => $enquiry->principal_quote_date ?? optional($quotedDateItem)->principal_quote_date,
                'price'                    => $enquiry->price,
                'status'                   => $enquiry->status,
                'linked_customer_quote_id' => $enquiry->linked_customer_quote_id ?? optional($linkedItem)->linked_customer_quote_id,

                'items' => $items->groupBy('item_id')->map(function ($group) {
                    $row = $group->sortBy('id')->first();
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
                        'is_converted'  => $row->enquiry_type !== 'principal_quotation',
                        'received_via'  => $row->received_via,
                    ];
                })->values()
            ];
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

    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
    private function applyFilters($query, Request $req)
    {
        // Search
        if ($req->search) {
            $query->where(function ($q) use ($req) {
                $q->where('enquiry_type', 'like', "%{$req->search}%")
                  ->orWhere('principal_quote_no', 'like', "%{$req->search}%")
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
        $allowedSorts = ['id', 'principal_quote_date', 'status', 'created_at'];

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

        // Update the shared/head-level fields across the whole group.
        // NOTE: principal_quote_no and received_via fall back to the existing
        // value when the request sends them empty, instead of blanking them
        // out on every item in the group — the edit form can legitimately
        // submit "" here if that field wasn't touched.
        Inquiry::where('parent_id', $parentId)->update([
            'principal_quote_no'   => $request->principal_quote_no !== ''
                ? $request->principal_quote_no
                : $inquiry->principal_quote_no,
            'principal_quote_date' => $request->principal_quote_date,
            'customer_id'          => $request->customer_id,
            'principal_id'         => $request->principal_id,
            'status'               => $request->status,
            'received_via'         => $request->received_via !== ''
                ? $request->received_via
                : $inquiry->received_via,
            'updated_by'           => Auth::id(),
        ]);

        foreach ($request->items ?? [] as $item) {

            if (empty($item['id'])) {

                // ── NEW ITEM: frontend sent no id, so there's nothing to
                // find/update — insert a new Inquiry row for it instead of
                // silently skipping it. Mirrors the row-creation logic in
                // store(), but attaches to the existing parent group.
                $newItem = Inquiry::create([
                    'parent_id'             => $parentId,

                    'inquiry_code'          => $inquiry->inquiry_code,
                    'inquiry_id'            => $inquiry->inquiry_id,

                    'enquiry_type'          => 'principal_quotation',

                    'customer_id'           => $request->customer_id,
                    'principal_id'          => $request->principal_id,

                    'item_id'               => $item['item_id'] ?? null,
                    'quantity'              => $item['qty'] ?? $item['quantity'] ?? 0,
                    'price'                 => $item['price'] ?? 0,

                    'principal_quote_no'    => $request->principal_quote_no !== ''
                        ? $request->principal_quote_no
                        : $inquiry->principal_quote_no,
                    'principal_quote_date'  => $request->principal_quote_date,
                    'received_via'          => $request->received_via !== ''
                        ? $request->received_via
                        : $inquiry->received_via,

                    'status'                => $item['status'] ?? $request->status,

                    'user_id'               => Auth::id(),
                    'created_by'            => Auth::id(),
                    'updated_by'            => Auth::id(),
                ]);

                // Same catalog sync as the update-path below, so a newly
                // added item's mat_code/name/description/part_model_no
                // (if the user typed them fresh) land on the Item record.
                if ($newItem->item_id) {

                    $catalogItem = Item::find($newItem->item_id);

                    if ($catalogItem) {
                        $catalogItem->update([
                            'mat_code'      => $item['mat_code']      ?? $catalogItem->mat_code,
                            'name'          => $item['item_name']     ?? $catalogItem->name,
                            'description'   => $item['description']   ?? $catalogItem->description,
                            'part_model_no' => $item['part_model_no'] ?? $catalogItem->part_model_no,
                        ]);
                    }
                }

                continue;
            }

            $inquiryItem = Inquiry::find($item['id']);

            if (!$inquiryItem) {
                continue;
            }

            // Update the inquiry-line-level fields (price, quantity).
            // NOTE: the frontend sends the quantity field as "qty", not
            // "quantity" — check both so this doesn't silently fall back
            // to the old stored value.
            $inquiryItem->update([
                'price'      => $item['price'] ?? null,
                'quantity'   => $item['qty'] ?? $item['quantity'] ?? $inquiryItem->quantity,
                'updated_by' => Auth::id(),
            ]);

            // material_code / item_name / part_model_no / description live on
            // the related Item catalog record, not on the inquiries table —
            // they were previously never written here, which is why edits to
            // them never showed up. Sync the catalog record too.
            if ($inquiryItem->item_id) {

                $catalogItem = Item::find($inquiryItem->item_id);

                if ($catalogItem) {
                    $catalogItem->update([
                        'mat_code'      => $item['mat_code']      ?? $catalogItem->mat_code,
                        'name'          => $item['item_name']     ?? $catalogItem->name,
                        'description'   => $item['description']   ?? $catalogItem->description,
                        'part_model_no' => $item['part_model_no'] ?? $catalogItem->part_model_no,
                    ]);
                }
            }
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

                'inquiry_id'             => 'nullable|string|max:20',
                'inquiry_code'           => 'nullable|string|max:20',
                'principal_quote_no'     => 'nullable|integer',
                'principal_quote_date'   => 'nullable|date',
                'customer_id'            => 'nullable|exists:users,id',
                'principal_id'           => 'nullable|exists:users,id',
                'status'                 => 'nullable',

                'items'                  => 'nullable|array',
                'items.*.item_id'        => 'nullable|exists:items,id',
                'items.*.quantity'       => 'nullable|integer',
                'items.*.price'          => 'nullable|numeric|min:0',
            ]);

            DB::beginTransaction();

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