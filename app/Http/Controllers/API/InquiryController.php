<?php
// app\Http\Controllers\API\InquiryController.php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Item;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InquiryController extends Controller
{
/**
     * Create Inquiry
     */
    public function storeBC(Request $req)
    {
        try {
            $req->validate([
                'enquiry_type'     => 'required|string|max:100',
                'customer_ids'     => 'required|array',
                'customer_ids.*'   => 'exists:users,id',
                'concerned_person' => 'required|string|max:255',
                'concerned_area'   => 'nullable|string|max:255',
                //'product_details'  => 'required|string',
                'item_id'          => 'required|exists:items,id',
                'quantity'         => 'required|integer|min:1',
                'principal_id'     => 'required|integer',
                'status'           => 'required|in:Price Requested,Pending,Closed,Completed,Regred'
            ]);

            // Generate Inquiry Code (INQ001)
            $last = DB::table('inquiries')->orderBy('id', 'desc')->first();

            $number = $last && $last->inquiry_code 
                ? intval(substr($last->inquiry_code, 3)) + 1 
                : 1;

            $inquiryCode = 'INQ' . str_pad($number, 3, '0', STR_PAD_LEFT);

            $inquiry = Inquiry::create([
                'inquiry_code'     => $inquiryCode,
                'enquiry_type'     => $req->enquiry_type,
                'customer_ids'     => json_encode($req->customer_ids),
                'concerned_person' => $req->concerned_person,
                'concerned_area'   => $req->concerned_area,
                //'product_details'  => $req->product_details,
                'item_id'          => $req->item_id,
                'quantity'         => $req->quantity,
                'principal_id'     => $req->principal_id,
                'status'           => $req->status,
                'user_id'          => Auth::id(),
                'created_by'       => Auth::id(),
                'updated_by'       => Auth::id()
            ]);

            // Audit log
            saveLog('CREATE_INQUIRY', 'Inquiry', $inquiry);

            return response()->json([
                'status'=> 200,
                'message' => 'Inquiry created successfully',
                'data'    => $inquiry
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status'=> 422,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Inquiry creation error: '.$e->getMessage());
            return response()->json([
                'status'=> 500,
                'message' => 'Failed to create inquiry. Please try again later.'
            ], 500);
        }
    }

public function store(Request $req)
    {
        DB::beginTransaction();

        try {

            $req->validate([
                'enquiry_type'     => 'nullable|string|max:100',
                'inquiry_id'       => 'nullable|string|max:100',
                'customer_id'      => 'nullable|exists:users,id',
                'concerned_person' => 'nullable|string|max:255',
                'concerned_area'   => 'nullable|string|max:255',
                'principal_id'     => 'exists:users,id',
                'status'           => 'in:Price Requested,Pending,Closed,Completed,Regret',
                'date'             => 'nullable|date',
                'url'              => 'nullable|string|max:255',
            ]);

            $last = Inquiry::orderBy('id', 'desc')->first();

            $number = $last && $last->inquiry_code
                ? intval(substr($last->inquiry_code, 3)) + 1
                : 1;

            $inquiryCode = 'INQ' . str_pad($number, 5, '0', STR_PAD_LEFT);

            $inquiry = Inquiry::create([
                'parent_id'        => null,
                'inquiry_id'       => $req->inquiry_id,
                'inquiry_code'     => $inquiryCode,
                'inquiry_date'     => $req->date,

                'enquiry_type'     => $req->enquiry_type,
                'customer_id'      => $req->customer_id,

                'concerned_person' => $req->concerned_person,
                'concerned_area'   => $req->concerned_area,

                'principal_id'     => $req->principal_id,
                'status'           => $req->status,
                'url'              => $req->url,

                'user_id'          => Auth::id(),
                'created_by'       => Auth::id(),
                'updated_by'       => Auth::id(),
            ]);

            // Single-row record → it is its own parent (no item children to group).
            $inquiry->update([
                'parent_id' => $inquiry->id,
            ]);

            $parentId = $inquiry->id;

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Inquiry created successfully',
                'inquiry_code' => $inquiryCode,
                'parent_id' => $parentId,
            ]);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Inquiry create error : ' . $e->getMessage());

            return response()->json([
                'status' => 500,
                'message' => 'Failed to create inquiry',
            ], 500);
        }
    }
    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
   /**
 * COMMON FILTER FUNCTION (REUSABLE)
 */
private function applyFilters($query, Request $req)
{
    // Search
    if ($req->search) {
        $query->where(function ($q) use ($req) {
            $q->where('enquiry_type', 'like', "%{$req->search}%")
              ->orWhere('concerned_person', 'like', "%{$req->search}%")
              ->orWhere('concerned_area', 'like', "%{$req->search}%")
              ->orWhere('product_details', 'like', "%{$req->search}%");
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
    $allowedSorts = ['id', 'enquiry_type', 'status', 'created_at'];

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

    /**
     * My Inquiry (User)
     */
   public function myInquiry(Request $req)
{
    $query = Inquiry::with([
        'item:id,name,description'
    ])
    ->where('user_id', Auth::id())
    ->whereColumn('id', 'parent_id');

    $query = $this->applyFilters($query, $req);

    $perPage = $req->per_page ?? 10;

    $data = $query->paginate($perPage);

    $customerIds = $data->pluck('customer_id')->unique();

    $users = User::whereIn('id', $customerIds)
        ->pluck('name', 'id');

    $parentIds = $data->pluck('id');

    $itemCounts = Inquiry::whereIn('parent_id', $parentIds)
        ->selectRaw('parent_id, COUNT(*) as total')
        ->groupBy('parent_id')
        ->pluck('total', 'parent_id');

    $data->getCollection()->transform(function ($item) use ($users, $itemCounts) {

        $item->customer_name = $users[$item->customer_id] ?? null;
        $item->total_items = $itemCounts[$item->id] ?? 0;

        return $item;
    });

    return response()->json([
        'status'  => 200,
        'message' => 'My inquiries fetched successfully',
        'data'    => $data
    ]);
}

public function inquiryDetails($parentId)
{
    $data = Inquiry::with('item:id,name,description')
        ->where('parent_id', $parentId)
        ->get();

    return response()->json([
        'status' => 200,
        'data'   => $data
    ]);
}
    
    public function show($parentId)
{
    $data = Inquiry::with('item')
        ->where('parent_id', $parentId)
        ->get();

    return response()->json([
        'status' => 200,
        'data' => $data
    ]);
}


    /**
     * All Inquiry (Admin)
     */
    public function allInquiry(Request $req)
{
    $user = Auth::user();

    if ($user->role !== 'admin') {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $query = Inquiry::with([
        'customer:id,name,email',
        'principal:id,name,email',
        //'item:id,name,description'
    ])
    ->whereColumn('id', 'parent_id'); // only parent records

    $query = $this->applyFilters($query, $req);

    $perPage = $req->per_page ?? 10;

    $data = $query->paginate($perPage);

    $data->getCollection()->transform(function ($parent) {

        // load child items (inquiry lines)
        $children = Inquiry::with('item:id,name,mat_code,part_model_no,description')
            ->where('parent_id', $parent->id)
            ->get();

        $parent->setAttribute('items', $children);

        return $parent;
    });

    return response()->json([
        'status' => 200,
        'message' => 'All inquiries fetched successfully',
        'data' => $data
    ]);
}

    

    public function update(Request $req, $id)
    {
        DB::beginTransaction();

        try {
            $inquiry = Inquiry::find($id);
            if (!$inquiry) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Inquiry not found'
                ], 404);
            }

            if ($inquiry->user_id != Auth::id() && Auth::user()->role !== 'admin') {
                return response()->json([
                    'status' => 403,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $req->validate([
                'enquiry_type'      => 'sometimes|required|string|max:100',
                'inquiry_id'        => 'sometimes|nullable|string|max:100',
                'customer_id'       => 'sometimes|required|exists:users,id',
                'principal_id'      => 'sometimes|required|exists:users,id',
                'concerned_person'  => 'sometimes|required|string|max:255',
                'concerned_area'    => 'nullable|string|max:255',
                'status'            => 'sometimes|required|in:Price Requested,Pending,Closed,Completed,Regret',
                'url'               => 'nullable|url',
                'date'              => 'sometimes|required|date',
                'items'             => 'sometimes|array',
                'items.*.item_id'   => 'required_with:items|exists:items,id',
                'items.*.quantity'  => 'integer',
                'items.*.date'      => 'nullable|date',
            ]);

            $oldData = $inquiry->toArray();

            // parent_id is the id shared by every row belonging to this enquiry
            $parentId = $inquiry->parent_id ?: $inquiry->id;

            $sharedFields = [
                'inquiry_id'       => $req->inquiry_id ?? $inquiry->inquiry_id,
                'inquiry_date'     => $req->date ?? $inquiry->inquiry_date,
                'enquiry_type'     => $req->enquiry_type ?? $inquiry->enquiry_type,
                'customer_id'      => $req->customer_id ?? $inquiry->customer_id,
                'concerned_person' => $req->concerned_person ?? $inquiry->concerned_person,
                'concerned_area'   => $req->concerned_area ?? $inquiry->concerned_area,
                'principal_id'     => $req->principal_id ?? $inquiry->principal_id,
                'status'           => $req->status ?? $inquiry->status,
                'url'              => $req->url ?? $inquiry->url,
                'updated_by'       => Auth::id(),
            ];

            // Update every row belonging to this enquiry (parent + all item rows)
            Inquiry::where('parent_id', $parentId)->update($sharedFields);

            // Update / add / remove item lines if provided
            if ($req->has('items') && is_array($req->items) && count($req->items) > 0) {
                $existingRows = Inquiry::where('parent_id', $parentId)->get();

                foreach ($req->items as $index => $item) {
                    if (isset($existingRows[$index])) {
                        $existingRows[$index]->update([
                            'item_id'  => $item['item_id'],
                            'quantity' => $item['quantity'],
                            'inquiry_date' => $item['date'] ?? $existingRows[$index]->inquiry_date,
                        ]);
                    } else {
                        Inquiry::create(array_merge($sharedFields, [
                            'parent_id'  => $parentId,
                            'item_id'    => $item['item_id'],
                            'quantity'   => $item['quantity'],
                            'inquiry_date' => $item['date'] ?? $sharedFields['inquiry_date'],
                            'user_id'    => $inquiry->user_id,
                            'created_by' => Auth::id(),
                        ]));
                    }
                }

                if ($existingRows->count() > count($req->items)) {
                    $existingRows->slice(count($req->items))
                        ->each(fn ($row) => $row->delete());
                }
            }

            $inquiry->refresh();

            saveLog('UPDATE_INQUIRY', 'Inquiry', [
                'old' => $oldData,
                'new' => $inquiry
            ]);

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Inquiry updated successfully',
                'data'    => $inquiry
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Inquiry update error: '.$e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update inquiry. Please try again later.'
            ], 500);
        }
    }

    /**
     * Delete Inquiry
     */
    public function destroy($id)
    {
        try {
            // Find inquiry or throw 404
            $inquiry = Inquiry::findOrFail($id);

            // Authorization check
            if ($inquiry->user_id != Auth::id() && Auth::user()->role !== 'admin') {
                return response()->json([
                    'status' => 403,
                    'message' => 'Unauthorized'
                    ], 403);
            }

            // Audit Log
            saveLog('DELETE_INQUIRY', 'Inquiry',  $inquiry);

            // Delete
            $inquiry->delete();

            return response()->json([
                'status'=> 200,
                'message' => 'Inquiry deleted successfully'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Inquiry not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Inquiry deletion error: '.$e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete inquiry. Please try again later.'.$e->getMessage()
            ], 500);
        }
    }








  




public function convertToprincipalqutation(Request $request, $parentId)
{
    DB::beginTransaction();

    try {

        // Look up the group by parent_id directly — any row belonging to
        // this group works as the "first" reference row.
        $firstInquiry = Inquiry::where('parent_id', $parentId)->first();

        if (!$firstInquiry) {
            return response()->json([
                'status' => 404,
                'message' => 'Inquiry not found'
            ], 404);
        }

        if ($firstInquiry->user_id != Auth::id() && Auth::user()->role != 'admin') {
            return response()->json([
                'status' => 403,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            // 'principal_quote_no'   => 'string|max:255',
            'principal_quote_no'   => 'nullable|string|max:255',
            'principal_quote_date' => 'required|date',
            // 'status'               => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
            'status'               => 'required|in:Price Requested,Price Received,Completed',

            'items'                => 'required|array|min:1',
            'items.*.id'           => 'nullable|integer|exists:inquiries,id',
            'items.*.price'         => 'nullable|numeric|min:0',
            'items.*.item_id'       => 'nullable|integer|exists:items,id',
            'items.*.mat_code'      => 'nullable|string|max:30',
            'items.*.item_name'     => 'nullable|string|max:255',
            'items.*.description'   => 'nullable|string',
            'items.*.part_model_no' => 'nullable|string|max:30',
            'items.*.qty'           => 'nullable|integer|min:1',
            'items.*.date'          => 'nullable|date',
        ]);

        // Same parent_id, always — no new group is ever created.
        $newParentId = $parentId;

        $existingRowsById = Inquiry::whereIn('id', collect($validated['items'])->pluck('id')->filter())
            ->get()
            ->keyBy('id');

        $resolvedItems = collect($validated['items'])->map(function ($item) use ($existingRowsById) {

            $existingRow = !empty($item['id']) ? $existingRowsById->get($item['id']) : null;

            if (!empty($item['item_id'])) {
                $catalogItem = Item::find($item['item_id']);
            } elseif ($existingRow && $existingRow->item_id) {
                $catalogItem = Item::find($existingRow->item_id);
            }

            if (empty($catalogItem)) {
                $catalogItem = Item::firstOrCreate(
                    ['mat_code' => $item['mat_code']],
                    [
                        'name'          => $item['item_name'] ?: $item['mat_code'],
                        'part_model_no' => $item['part_model_no'] ?? '',
                        'description'   => $item['description'] ?? '',
                    ]
                );
            }

            $catalogItem->fill([
                'mat_code'      => $item['mat_code'],
                'name'          => !empty($item['item_name']) ? $item['item_name'] : $catalogItem->name,
                'description'   => $item['description'] ?? $catalogItem->description,
                'part_model_no' => $item['part_model_no'] ?? $catalogItem->part_model_no,
            ])->save();

            $item['item_id'] = $catalogItem->id;
            $item['_existing_row'] = $existingRow;

            return $item;
        });

        $existingIds = Inquiry::where('parent_id', $newParentId)
            ->pluck('id')
            ->toArray();

        foreach ($resolvedItems as $item) {

            // Item-related fields only — these are the only things that
            // ever change on an update or differ on a new row.
            $itemFields = [
                'item_id'      => $item['item_id'],
                'price'        => $item['price'],
                'quantity'     => $item['qty'] ?? null,
                'inquiry_date' => $item['date'] ?? null,
            ];

            $sharedFields = [
                'enquiry_type'         => 'principal_quotation',
                // 'principal_quote_no'   => $validated['principal_quote_no'],
                'principal_quote_no'   => $validated['principal_quote_no'] ?? null,
                'principal_quote_date' => $validated['principal_quote_date'],
                'status'               => $validated['status'],
                'updated_by'           => Auth::id(),
                'updated_at'           => now(),
            ];

            if (!empty($item['id']) && in_array($item['id'], $existingIds)) {

                // Update only this specific row — no other siblings touched.
                Inquiry::where('id', $item['id'])->update(array_merge($sharedFields, $itemFields));

            } else {

                // New item — copy the entire first inquiry row and only
                // override the item-related fields (plus the quote-level
                // fields being set on this request).
                $newInquiry = $firstInquiry->replicate();

                $newInquiry->fill(array_merge($sharedFields, $itemFields, [
                    'parent_id'      => $newParentId,
                    'inquiry_code'   => $firstInquiry->inquiry_code,
                    'inquiry_id'     => $firstInquiry->inquiry_id,
                    'user_id'        => $firstInquiry->user_id,
                    'created_by'     => Auth::id(),
                    'created_at'     => now(),
                ]));

                $newInquiry->save();
            }

        }

        DB::commit();

        return response()->json([
            'status' => 200,
            'message' => 'Principal quotation converted successfully.',
            'parent_id' => $newParentId
        ]);

    } catch (ValidationException $e) {

        DB::rollBack();

        return response()->json([
            'status' => 422,
            'errors' => $e->errors()
        ], 422);

    } catch (\Throwable $e) {

        DB::rollBack();

        Log::error($e->getMessage());

        return response()->json([
            'status' => 500,
            'message' => $e->getMessage()
        ], 500);
    }
}

}