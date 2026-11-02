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
use Illuminate\Validation\Rule;
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
                'enquiry_type' => 'nullable|string|max:100',
                'customer_ids' => 'nullable|array',
                'customer_ids.*' => 'exists:users,id',
                'concerned_person' => 'nullable|string|max:255',
                'concerned_area' => 'nullable|string|max:255',
                //'product_details'  => 'required|string',
                'item_id' => 'nullable',
                'quantity' => 'nullable',
                'principal_id' => 'nullable|integer',
                'status' => 'nullable|in:Price Requested,Pending,Closed,Completed,Regred'
            ]);

            // Generate Inquiry Code (INQ001)
            $last = DB::table('inquiries')->orderBy('id', 'desc')->first();

            $number = $last && $last->inquiry_code
                ? intval(substr($last->inquiry_code, 3)) + 1
                : 1;

            $inquiryCode = 'INQ' . str_pad($number, 3, '0', STR_PAD_LEFT);

            $inquiry = Inquiry::create([
                'inquiry_code' => $inquiryCode,
                'enquiry_type' => $req->enquiry_type,
                'customer_ids' => json_encode($req->customer_ids),
                'concerned_person' => $req->concerned_person,
                'concerned_area' => $req->concerned_area,
                //'product_details'  => $req->product_details,
                'item_id' => $req->item_id,
                'quantity' => $req->quantity,
                'principal_id' => $req->principal_id,
                'status' => $req->status,
                'user_id' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id()
            ]);

            // Audit log
            saveLog('CREATE_INQUIRY', 'Inquiry', $inquiry);

            return response()->json([
                'status' => 200,
                'message' => 'Inquiry created successfully',
                'data' => $inquiry
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Inquiry creation error: ' . $e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to create inquiry. Please try again later.'
            ], 500);
        }
    }





    public function store(Request $req)
    {
        DB::beginTransaction();

        try {

            // Trim only — no case-forcing needed anymore since alphabetic
            // values skip the uniqueness check entirely per requirement below.
            if ($req->filled('inquiry_id')) {
                $req->merge(['inquiry_id' => trim($req->inquiry_id)]);
            }

            $req->validate([
                'enquiry_type' => 'nullable|string|max:100',
                'inquiry_id' => 'nullable|string|max:100',
                'customer_id' => 'nullable|exists:users,id',
                'concerned_person' => 'nullable|string|max:255',
                'concerned_area' => 'nullable|string|max:255',
                'principal_id' => 'nullable|exists:users,id',
                'status' => 'nullable|in:Price Requested,Pending,Closed,Completed,Regret',
                'date' => 'nullable|date',
                'url' => 'nullable|string|max:255',
                'enq_due' => 'nullable|date',
                'assigned_to' => 'nullable',
                'short_desc' => 'nullable|string|max:500',
                'ref' => 'nullable|string|max:255',
                'priority' => 'nullable|in:Low,Medium,High',
            ]);

            // Generate Inquiry Code — lock the row with the highest
            // inquiry_code number so concurrent requests can't read the
            // same "latest" value and generate duplicate codes.
            $last = Inquiry::whereNotNull('inquiry_code')
                ->orderByRaw('CAST(SUBSTRING(inquiry_code, 4) AS UNSIGNED) DESC')
                ->lockForUpdate()
                ->first();

            $number = $last
                ? ((int) substr($last->inquiry_code, 3)) + 1
                : 1;

            $inquiryCode = 'INQ' . str_pad($number, 5, '0', STR_PAD_LEFT);

            // Create Inquiry
            $inquiry = Inquiry::create([
                'parent_id' => null,
                'inquiry_id' => $req->inquiry_id,
                'inquiry_code' => $inquiryCode,
                'inquiry_date' => $req->date,
                'assigned_to' => $req->assigned_to,

                'enquiry_type' => $req->enquiry_type,
                'customer_id' => $req->customer_id,

                'concerned_person' => $req->concerned_person,
                'concerned_area' => $req->concerned_area,
                'priority' => $req->priority,

                'principal_id' => $req->principal_id,
                'status' => $req->status,
                'url' => $req->url,
                'short_desc' => $req->short_desc,
                'ref' => $req->ref,
                'enq_due' => $req->enq_due,

                'user_id' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Set parent_id = own id
            $inquiry->parent_id = $inquiry->id;
            $inquiry->save();

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Inquiry created successfully.',
                'inquiry_code' => $inquiry->inquiry_code,
                'parent_id' => $inquiry->parent_id,
                'data' => $inquiry,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('Inquiry Create Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 500,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
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
                $end = $now->copy()->endOfWeek();
                break;

            case 'monthly':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;

            case 'quarterly':
                $start = $now->copy()->firstOfQuarter();
                $end = $now->copy()->lastOfQuarter();
                break;

            case 'yearly':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
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
            'status' => 200,
            'message' => 'My inquiries fetched successfully',
            'data' => $data
        ]);
    }

    public function inquiryDetails($parentId)
    {
        $data = Inquiry::with('item:id,name,description')
            ->where('parent_id', $parentId)
            ->get();

        return response()->json([
            'status' => 200,
            'data' => $data
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

        // if ($user->role !== 'admin') {
        //     return response()->json(['message' => 'Unauthorized'], 403);
        // }

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

            // load child items (inquiry lines) - first conversion items only
            $children = Inquiry::with('item:id,name,mat_code,part_model_no,description')
                ->where('parent_id', $parent->id)
                ->get();

            $firstConversionChildren = $children->groupBy('item_id')->map(function ($group) {
                return $group->sortBy('id')->first();
            })->values();

            $parent->setAttribute('items', $firstConversionChildren);

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

            $parentId = $inquiry->parent_id ?: $inquiry->id;

            $req->validate([
                'enquiry_type' => 'sometimes|nullable|string|max:100',
                'inquiry_id' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:100',
                    Rule::unique(Inquiry::class, 'inquiry_id')
                        ->where(fn($query) => $query->whereColumn('id', 'parent_id'))
                        ->ignore($parentId, 'parent_id'),
                ],
                'customer_id' => 'sometimes|nullable|exists:users,id',
                'principal_id' => 'sometimes|nullable|exists:users,id',
                'concerned_person' => 'sometimes|nullable|string|max:255',
                'concerned_area' => 'nullable|string|max:255',
                'status' => 'sometimes|nullable|in:Price Requested,Pending,Closed,Completed,Regret',
                'url' => 'nullable',
                'assigned_to' => 'nullable',
                'short_desc' => 'sometimes|nullable|string|max:500',
                'ref' => 'sometimes|nullable|string|max:255',
                'enq_due' => 'sometimes|nullable|date',
                'date' => 'sometimes|nullable|date',
                'items' => 'sometimes|array',
                'items.*.item_id' => 'nullable|exists:items,id',
                'items.*.quantity' => 'integer',
                'items.*.date' => 'nullable|date',
                'priority' => 'sometimes|nullable|in:Low,Medium,High',
            ]);

            $oldData = $inquiry->toArray();

            $sharedFields = [
                'inquiry_id' => $req->inquiry_id ?? $inquiry->inquiry_id,
                'inquiry_date' => $req->date ?? $inquiry->inquiry_date,
                // 'enquiry_type'     => $req->enquiry_type ?? $inquiry->enquiry_type,
                'customer_id' => $req->customer_id ?? $inquiry->customer_id,
                'concerned_person' => $req->concerned_person ?? $inquiry->concerned_person,
                'concerned_area' => $req->concerned_area ?? $inquiry->concerned_area,
                'principal_id' => $req->principal_id ?? $inquiry->principal_id,
                'received_via' => $req->received_via ?? $inquiry->received_via,
                'assigned_to' => $req->has('assigned_to') ? $req->assigned_to : $inquiry->assigned_to,
                'short_desc' => $req->has('short_desc') ? $req->short_desc : $inquiry->short_desc,
                'ref' => $req->has('ref') ? $req->ref : $inquiry->ref,
                'enq_due' => $req->has('enq_due') ? $req->enq_due : $inquiry->enq_due,
                'priority' => $req->has('priority') ? $req->priority : $inquiry->priority,
                'status' => $req->status ?? $inquiry->status,
                'url' => $req->has('url') ? $req->url : $inquiry->url,
                'updated_by' => Auth::id(),
            ];

            Inquiry::where('parent_id', $parentId)->update($sharedFields);

            if ($req->has('items') && is_array($req->items) && count($req->items) > 0) {
                $existingRows = Inquiry::where('parent_id', $parentId)->get();
                $existingRowsById = $existingRows->keyBy('id');
                $keptIds = [];

                foreach ($req->items as $item) {
                    $existingRow = !empty($item['id'])
                        ? $existingRowsById->get($item['id'])
                        : null;

                    if ($existingRow) {
                        $existingRow->update([
                            'item_id' => $item['item_id'],
                            'quantity' => $item['quantity'],
                            'inquiry_date' => $item['date'] ?? $existingRow->inquiry_date,
                        ]);
                        $keptIds[] = $existingRow->id;
                    } else {
                        $newRow = Inquiry::create(array_merge($sharedFields, [
                            'parent_id' => $parentId,
                            'item_id' => $item['item_id'],
                            'quantity' => $item['quantity'],
                            'inquiry_date' => $item['date'] ?? $sharedFields['inquiry_date'],
                            'user_id' => $inquiry->user_id,
                            'created_by' => Auth::id(),
                        ]));
                        $keptIds[] = $newRow->id;
                    }
                }

                // IMPORTANT: never delete the row currently being edited ($inquiry),
                // even if the incoming "items" payload didn't reference its id.
                // Previously this could delete $inquiry itself, causing
                // $inquiry->refresh() below to throw a ModelNotFoundException
                // ("No query results for model [App\Models\Inquiry]").
                $existingRows
                    ->reject(fn($row) => in_array($row->id, $keptIds) || $row->id === $inquiry->id)
                    ->each(fn($row) => $row->delete());
            }

            // Defensive check: if $inquiry somehow no longer exists in the DB,
            // fail cleanly instead of letting refresh() throw an uncaught
            // ModelNotFoundException.
            if (!$inquiry->exists || !Inquiry::where('id', $inquiry->id)->exists()) {
                DB::rollBack();
                return response()->json([
                    'status' => 500,
                    'message' => 'Inquiry record no longer exists after update'
                ], 500);
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
                'data' => $inquiry
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Inquiry update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 500,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
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
            saveLog('DELETE_INQUIRY', 'Inquiry', $inquiry);

            // Delete
            $inquiry->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Inquiry deleted successfully'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Inquiry not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Inquiry deletion error: ' . $e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete inquiry. Please try again later.' . $e->getMessage()
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
                'principal_quote_no' => 'nullable|string|max:255',
                'principal_quote_date' => 'nullable|date',
                'status' => 'nullable|in:Price Requested,Price Received,Completed',
                'received_via' => 'nullable|in:LPO,Email,WhatsApp',

                'items' => 'nullable|array',
                'items.*.id' => 'nullable|integer|exists:inquiries,id',
                'items.*.price' => 'nullable|numeric|min:0',
                'items.*.item_id' => 'nullable|integer|exists:items,id',
                'items.*.mat_code' => 'nullable|string|max:30',
                'items.*.item_name' => 'nullable|string|max:255',
                'items.*.description' => 'nullable|string',
                'items.*.part_model_no' => 'nullable|string|max:30',
                'items.*.qty' => 'nullable|integer|min:1',
                'items.*.date' => 'nullable|date',
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
                            'name' => $item['item_name'] ?: $item['mat_code'],
                            'part_model_no' => $item['part_model_no'] ?? '',
                            'description' => $item['description'] ?? '',
                        ]
                    );
                }

                $catalogItem->fill([
                    'mat_code' => $item['mat_code'],
                    'name' => !empty($item['item_name']) ? $item['item_name'] : $catalogItem->name,
                    'description' => $item['description'] ?? $catalogItem->description,
                    'part_model_no' => $item['part_model_no'] ?? $catalogItem->part_model_no,
                ])->save();

                $item['item_id'] = $catalogItem->id;
                $item['_existing_row'] = $existingRow;

                return $item;
            });

            $existingIds = Inquiry::where('parent_id', $newParentId)
                ->pluck('id')
                ->toArray();

            // Collect what actually happened to each item, for the audit log.
            $touchedItems = [];

            foreach ($resolvedItems as $item) {

                // Item-related fields only — these are the only things that
                // ever change on an update or differ on a new row.
                $itemFields = [
                    'item_id' => $item['item_id'],
                    'price' => $item['price'],
                    'quantity' => $item['qty'] ?? null,
                    'inquiry_date' => $item['date'] ?? null,
                ];

                $sharedFields = [
                    'enquiry_type' => 'principal_quotation',
                    'principal_quote_no' => $validated['principal_quote_no'] ?? null,
                    'principal_quote_date' => $validated['principal_quote_date'],
                    'received_via' => $validated['received_via'] ?? null,
                    'status' => $validated['status'],
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ];

                if (!empty($item['id']) && in_array($item['id'], $existingIds)) {

                    // Update only this specific row — no other siblings touched.
                    Inquiry::where('id', $item['id'])->update(array_merge($sharedFields, $itemFields));

                    $touchedItems[] = [
                        'action' => 'updated',
                        'inquiry_id' => $item['id'],
                        'item_id' => $item['item_id'],
                        'mat_code' => $item['mat_code'] ?? null,
                        'item_name' => $item['item_name'] ?? null,
                        'description' => $item['description'] ?? null,
                        'part_model_no' => $item['part_model_no'] ?? null,
                        'price' => $item['price'] ?? null,
                        'quantity' => $item['qty'] ?? null,
                        'inquiry_date' => $item['date'] ?? null,
                    ];

                } else {

                    // New item — copy the entire first inquiry row and only
                    // override the item-related fields (plus the quote-level
                    // fields being set on this request).
                    $newInquiry = $firstInquiry->replicate();

                    $newInquiry->fill(array_merge($sharedFields, $itemFields, [
                        'parent_id' => $newParentId,
                        'inquiry_code' => $firstInquiry->inquiry_code,
                        'inquiry_id' => $firstInquiry->inquiry_id,
                        'user_id' => $firstInquiry->user_id,
                        'created_by' => Auth::id(),
                        'created_at' => now(),
                    ]));

                    $newInquiry->save();

                    $touchedItems[] = [
                        'action' => 'created',
                        'inquiry_id' => $newInquiry->id,
                        'item_id' => $item['item_id'],
                        'mat_code' => $item['mat_code'] ?? null,
                        'item_name' => $item['item_name'] ?? null,
                        'description' => $item['description'] ?? null,
                        'part_model_no' => $item['part_model_no'] ?? null,
                        'price' => $item['price'] ?? null,
                        'quantity' => $item['qty'] ?? null,
                        'inquiry_date' => $item['date'] ?? null,
                    ];
                }

            }

            DB::commit();

            // Audit log — records the quote header plus every item touched.
            saveLog('CONVERT_ENQUIRY_TO_PRINCIPAL', 'Inquiry', [
                'parent_id' => $newParentId,
                'inquiry_code' => $firstInquiry->inquiry_code,
                'principal_quote_no' => $validated['principal_quote_no'] ?? null,
                'principal_quote_date' => $validated['principal_quote_date'],
                'received_via' => $validated['received_via'] ?? null,
                'status' => $validated['status'],
                'items' => $touchedItems,
            ]);

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









    /**
     * Merge one or more Enquiries/BOs into a single new Enquiry.
     * Accepts source_ids: [1, 2] — the new Enquiry's items are the union
     * of all items belonging to those sources, and merged_source_ids
     * records which ones were merged so the UI can display it.
     */
    public function convertBoToEnquiry(Request $req)
    {
        $validated = $req->validate([
            'inquiry_id' => 'nullable|string|max:100', // RFQ No.
            'source_ids' => 'nullable|array',
            'source_ids.*' => 'integer|exists:inquiries,id',
            'item_ids' => 'sometimes|array',
            'item_ids.*' => 'integer',
            'inquiry_date' => 'sometimes|nullable|date',
            'customer_name' => 'sometimes|nullable|string|max:255',
            'principal_name' => 'sometimes|nullable|string|max:255',
            'url' => 'sometimes|nullable|string|max:255',
            'concerned_person' => 'sometimes|nullable|string|max:255',
            'short_desc' => 'sometimes|nullable|string|max:1000',
            'ref' => 'sometimes|nullable|string|max:255',
            'enq_due' => 'sometimes|nullable|date',
            'assigned_to' => 'sometimes|nullable|string|max:255',
            'priority' => 'sometimes|nullable|string|in:Low,Medium,High',
        ]);

        $sources = Inquiry::whereIn('id', $validated['source_ids'])->get();

        if ($sources->isEmpty()) {
            return response()->json([
                'status' => 404,
                'message' => 'No valid source Enquiries/BOs found.',
            ], 404);
        }

        // Use the first source purely to seed header fields (customer,
        // principal, concerned person/area, url) on the new record.
        $primary = $sources->first();

        // Resolve customer/principal from the names the user may have edited
        // in the "Convert to Enquiry" form. Customers and principals are both
        // just Users with a role column ('customer' / 'vendor') — there's no
        // separate Customer/Principal model — so look them up on User and
        // fall back to the primary source's ids when no name was given or it
        // didn't match a known record.
        $customerId = $primary->customer_id;
        if (!empty($validated['customer_name'])) {
            $matchedCustomer = User::where('role', 'customer')
                ->whereRaw('LOWER(name) = ?', [strtolower($validated['customer_name'])])
                ->first();
            if ($matchedCustomer) {
                $customerId = $matchedCustomer->id;
            }
        }

        $principalId = $primary->principal_id;
        if (!empty($validated['principal_name'])) {
            $matchedPrincipal = User::where('role', 'vendor')
                ->whereRaw('LOWER(name) = ?', [strtolower($validated['principal_name'])])
                ->first();
            if ($matchedPrincipal) {
                $principalId = $matchedPrincipal->id;
            }
        }

        DB::beginTransaction();

        try {
            // Generate a NEW Enquiry ID (INQ00006, ...) — never reuse a
            // source's inquiry_code, otherwise the merged Enquiry looks
            // like it collapsed back into one of its sources.
            //
            // IMPORTANT: order by the NUMERIC value of inquiry_code, not by
            // row id — the row with the highest id is not guaranteed to have
            // the highest inquiry_code number, which previously caused
            // duplicate/stale codes (e.g. INQ00002 got reused).
            // lockForUpdate() prevents two concurrent requests picking the
            // same next number.
            $last = Inquiry::where('inquiry_code', 'like', 'INQ%')
                ->orderByRaw("CAST(SUBSTRING(inquiry_code, 4) AS UNSIGNED) DESC")
                ->lockForUpdate()
                ->first();

            $number = $last
                ? intval(substr($last->inquiry_code, 3)) + 1
                : 1;

            $newInquiryCode = 'INQ' . str_pad($number, 5, '0', STR_PAD_LEFT);

            // Safety net: if codes and ids have drifted out of sync for any
            // reason (malformed historical rows, manual edits, etc.), fail
            // loudly instead of silently creating a duplicate inquiry_code.
            if (Inquiry::where('inquiry_code', $newInquiryCode)->exists()) {
                throw new \Exception("Generated inquiry_code {$newInquiryCode} already exists — code sequence is out of sync.");
            }

            // Snapshot of what got merged in, stored on the row so the
            // Enquiry list/detail page can show "Merged from: INQ00001, INQ00002"
            // without joining back through source_bo_id.
            $mergedSources = $sources->map(function ($s) {
                return [
                    'id' => $s->parent_id,
                    'inquiry_code' => $s->inquiry_code,
                    'budgetary_no' => $s->budgetary_no,
                ];
            })->values()->toArray();

            $newEnquiry = Inquiry::create([
                'parent_id' => null,
                'inquiry_id' => $validated['inquiry_id'],
                'inquiry_code' => $newInquiryCode,
                'inquiry_date' => $validated['inquiry_date'] ?? now(),

                'enquiry_type' => 'General',
                'customer_id' => $customerId,

                'concerned_person' => $validated['concerned_person'] ?? ($primary->concerned_person ?? 'N/A'),
                'concerned_area' => $primary->concerned_area,

                'principal_id' => $principalId,
                'status' => 'Pending',
                'url' => $validated['url'] ?? $primary->url,
                'short_desc' => $validated['short_desc'] ?? $primary->short_desc,
                'ref' => $validated['ref'] ?? $primary->ref,
                'enq_due' => $validated['enq_due'] ?? $primary->enq_due,
                'assigned_to' => $validated['assigned_to'] ?? $primary->assigned_to,
                'priority' => $validated['priority'] ?? $primary->priority,

                'user_id' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),

                'source_bo_id' => $primary->id,
                'merged_source_ids' => $mergedSources,
            ]);

            // Self-parent — standalone row, shows correctly in the Enquiry list.
            $newEnquiry->update(['parent_id' => $newEnquiry->id]);

            // Pull items from EVERY merged source's sibling group. This is what
            // actually combines Enquiry 1's items + Enquiry 2's items into
            // the new Enquiry 3.
            $sourceParentIds = $sources->pluck('parent_id')->unique()->values();

            $boItemsQuery = Inquiry::whereIn('parent_id', $sourceParentIds)
                ->whereNotNull('item_id');

            // Optional: still allow narrowing to specific checked items
            // across all sources, same as before.
            if ($req->has('item_ids') && is_array($req->item_ids) && count($req->item_ids) > 0) {
                $boItemsQuery->whereIn('id', $req->item_ids);
            }

            $boItems = $boItemsQuery->get();

            foreach ($boItems as $boItem) {
                Inquiry::create([
                    'parent_id' => $newEnquiry->id,
                    'inquiry_id' => $validated['inquiry_id'],
                    'inquiry_code' => $newEnquiry->inquiry_code,
                    'inquiry_date' => $validated['inquiry_date'] ?? now(),

                    'enquiry_type' => 'General',
                    'customer_id' => $boItem->customer_id ?? $customerId,

                    'concerned_person' => $boItem->concerned_person ?? ($validated['concerned_person'] ?? 'N/A'),
                    'concerned_area' => $boItem->concerned_area,

                    'principal_id' => $boItem->principal_id ?? $principalId,
                    'status' => 'Pending',
                    'url' => $boItem->url ?? ($validated['url'] ?? $primary->url),

                    'item_id' => $boItem->item_id,
                    'quantity' => $boItem->quantity,
                    'price' => $boItem->price,
                    'priority' => $boItem->priority,

                    'user_id' => Auth::id(),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),

                    // Track which specific source row this item came from,
                    // not just the "primary" source.
                    'source_bo_id' => $boItem->parent_id,
                ]);
            }

            // Attach items to the response so the frontend sees them
            // immediately (same shape allInquiry() uses).
            $newEnquiry->setAttribute(
                'items',
                Inquiry::with('item:id,name,mat_code,part_model_no,description')
                    ->where('parent_id', $newEnquiry->id)
                    ->get()
            );

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Merged into new Enquiry successfully.',
                'inquiry_code' => $newEnquiry->inquiry_code,
                'parent_id' => $newEnquiry->id,
                'merged_from' => $mergedSources,
                'data' => $newEnquiry,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('convertBoToEnquiry (merge) error: ' . $e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

}