<?php
// app\Http\Controllers\API\OpportunityController.php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Opportunity;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class OpportunityController extends Controller
{
    /**
     * Create Opportunity (head + item rows, same parent/child pattern as Inquiry)
     */
    // public function store(Request $req)
    // {
    //     DB::beginTransaction();

    //     try {
    //         $req->validate([
    //             'customer_id'      => 'required|exists:users,id',
    //             'principal_id'     => 'nullable|exists:users,id',
    //             'quotation_ref'    => 'nullable|string|max:255',
    //             'details'          => 'nullable|string',
    //             'attachment'       => 'nullable|string|max:255',
    //             'status'           => 'required|in:Open,In Progress,Converted,Lost',
    //             'opportunity_date' => 'nullable|date',

    //             'items'                  => 'required|array|min:1',
    //             'items.*.item_id'        => 'nullable|exists:items,id',
    //             'items.*.purchase_group' => 'nullable|string|max:255',
    //             'items.*.location'       => 'nullable|string|max:255',
    //             'items.*.quantity'       => 'nullable|integer|min:1',
    //             'items.*.date'           => 'nullable|date',
    //             'items.*.status'         => 'nullable|in:Pending,Quotation Placed,Completed',
    //         ]);

    //         // Generate Opportunity Code (OPP-001)
    //         $last = Opportunity::orderBy('id', 'desc')->first();

    //         $number = $last && $last->opportunity_code
    //             ? intval(preg_replace('/\D/', '', $last->opportunity_code)) + 1
    //             : 1;

    //         $opportunityCode = 'OPP-' . str_pad($number, 3, '0', STR_PAD_LEFT);

    //         $parentId = null;

    //         foreach ($req->items as $index => $item) {

    //             $opportunity = Opportunity::create([
    //                 'parent_id'        => null,
    //                 'opportunity_code' => $opportunityCode,
    //                 'opportunity_date' => $req->opportunity_date ?? now()->toDateString(),

    //                 'user_id'          => Auth::id(),

    //                 'customer_id'      => $req->customer_id,
    //                 'principal_id'     => $req->principal_id,

    //                 'quotation_ref'    => $req->quotation_ref,
    //                 'details'          => $req->details,
    //                 'attachment'       => $req->attachment,

    //                 'item_id'          => $item['item_id'] ?? null,
    //                 'purchase_group'   => $item['purchase_group'] ?? null,
    //                 'location'         => $item['location'] ?? null,
    //                 'quantity'         => $item['quantity'] ?? null,
    //                 'item_date'        => $item['date'] ?? now()->toDateString(),
    //                 'item_status'      => $item['status'] ?? 'Pending',

    //                 'status'           => $req->status,

    //                 'created_by'       => Auth::id(),
    //                 'updated_by'       => Auth::id(),
    //             ]);

    //             if ($index === 0) {
    //                 $parentId = $opportunity->id;
    //             }

    //             $opportunity->update(['parent_id' => $parentId]);
    //         }

    //         DB::commit();

    //         // Audit log
    //         saveLog('CREATE_OPPORTUNITY', 'Opportunity', ['opportunity_code' => $opportunityCode]);

    //         return response()->json([
    //             'status'           => 200,
    //             'message'          => 'Opportunity created successfully',
    //             'opportunity_code' => $opportunityCode,
    //             'parent_id'        => $parentId,
    //         ]);

    //     } catch (ValidationException $e) {

    //         DB::rollBack();

    //         return response()->json([
    //             'status'  => 422,
    //             'message' => 'Validation failed',
    //             'errors'  => $e->errors(),
    //         ], 422);

    //     } catch (\Exception $e) {

    //         DB::rollBack();

    //         Log::error('Opportunity create error : ' . $e->getMessage());

    //         return response()->json([
    //             'status'  => 500,
    //             'message' => 'Failed to create opportunity',
    //         ], 500);
    //     }
    // }

    public function store(Request $req)
{
    DB::beginTransaction();

    try {

    // Convert form-data JSON string into array
if ($req->has('items') && is_string($req->items)) {
    $decoded = json_decode($req->items, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return response()->json([
            'status' => 422,
            'message' => 'Invalid items JSON.',
            'error' => json_last_error_msg(),
        ], 422);
    }

    $req->merge([
        'items' => $decoded
    ]);
}

        $req->validate([
            'customer_id'      => 'required|exists:users,id',
            'principal_id'     => 'nullable|exists:users,id',
            'quotation_ref'    => 'nullable|string|max:255',
            'details'          => 'nullable|string',

            // File Validation
            'attachment'       => 'nullable|file|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx|max:5120',

            'status'           => 'required|in:Open,In Progress,Converted,Lost',
            'opportunity_date' => 'nullable|date',

            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'nullable|exists:items,id',
            'items.*.purchase_group' => 'nullable|string|max:255',
            'items.*.location'       => 'nullable|string|max:255',
            'items.*.quantity'       => 'nullable|integer|min:1',
            'items.*.date'           => 'nullable|date',
            'items.*.status'         => 'nullable|in:Pending,Quotation Placed,Completed',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Upload Attachment
        |--------------------------------------------------------------------------
        */

        $attachmentPath = null;

        if ($req->hasFile('attachment')) {

            $file = $req->file('attachment');

            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

           $destinationPath = public_path('storage/opportunities'); // ✅ Matches your folder

            // Create folder if not exists
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0777, true);
            }

            $file->move($destinationPath, $filename);

            $attachmentPath = 'storage/opportunities/' . $filename;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Opportunity Code
        |--------------------------------------------------------------------------
        */

        $last = Opportunity::orderBy('id', 'desc')->first();

        $number = $last && $last->opportunity_code
            ? intval(preg_replace('/\D/', '', $last->opportunity_code)) + 1
            : 1;

        $opportunityCode = 'OPP-' . str_pad($number, 3, '0', STR_PAD_LEFT);

        $parentId = null;

        /*
        |--------------------------------------------------------------------------
        | Save Opportunity Items
        |--------------------------------------------------------------------------
        */

        foreach ($req->items as $index => $item) {

            $opportunity = Opportunity::create([

                'parent_id'        => null,
                'opportunity_code' => $opportunityCode,
                'opportunity_date' => $req->opportunity_date ?? now()->toDateString(),

                'user_id'          => Auth::id(),

                'customer_id'      => $req->customer_id,
                'principal_id'     => $req->principal_id,

                'quotation_ref'    => $req->quotation_ref,
                'details'          => $req->details,
                'attachment'       => $attachmentPath,

                'item_id'          => $item['item_id'] ?? null,
                'purchase_group'   => $item['purchase_group'] ?? null,
                'location'         => $item['location'] ?? null,
                'quantity'         => $item['quantity'] ?? null,
                'item_date'        => $item['date'] ?? now()->toDateString(),
                'item_status'      => $item['status'] ?? 'Pending',

                'status'           => $req->status,

                'created_by'       => Auth::id(),
                'updated_by'       => Auth::id(),
            ]);

            if ($index === 0) {
                $parentId = $opportunity->id;
            }

            $opportunity->update([
                'parent_id' => $parentId
            ]);
        }

        DB::commit();

        // Audit Log
        saveLog('CREATE_OPPORTUNITY', 'Opportunity', [
            'opportunity_code' => $opportunityCode
        ]);

        return response()->json([
            'status'           => 200,
            'message'          => 'Opportunity created successfully.',
            'opportunity_code' => $opportunityCode,
            'parent_id'        => $parentId,
            'attachment'       => $attachmentPath,
        ], 200);

    } catch (ValidationException $e) {

        DB::rollBack();

        return response()->json([
            'status'  => 422,
            'message' => 'Validation failed.',
            'errors'  => $e->errors(),
        ], 422);

    } catch (\Exception $e) {

        DB::rollBack();

        Log::error('Opportunity create error: ' . $e->getMessage());

        return response()->json([
            'status'  => 500,
            'message' => 'Failed to create opportunity.',
            'error'   => $e->getMessage(), // Remove in production
        ], 500);
    }
}

    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
    private function applyFilters($query, Request $req)
    {
        if ($req->search) {
            $query->where(function ($q) use ($req) {
                $q->where('quotation_ref', 'like', "%{$req->search}%")
                  ->orWhere('details', 'like', "%{$req->search}%")
                  ->orWhere('opportunity_code', 'like', "%{$req->search}%");
            });
        }

        if ($req->from_date && $req->to_date) {
            $query->whereBetween('created_at', [
                $req->from_date . ' 00:00:00',
                $req->to_date . ' 23:59:59',
            ]);
        }

        if ($req->status) {
            $query->where('status', $req->status);
        }

        $allowedSorts = ['id', 'opportunity_code', 'status', 'created_at'];

        $sortBy = in_array($req->sort_by, $allowedSorts) ? $req->sort_by : 'created_at';
        $sortOrder = $req->sort_order === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    /**
     * My Opportunities (User)
     */
    public function myOpportunity(Request $req)
    {
        $query = Opportunity::where('user_id', Auth::id())
            ->whereColumn('id', 'parent_id'); // only head rows

        $query = $this->applyFilters($query, $req);

        $perPage = $req->per_page ?? 10;

        $data = $query->paginate($perPage);

        $this->attachListMeta($data);

        return response()->json([
            'status'  => 200,
            'message' => 'My opportunities fetched successfully',
            'data'    => $data,
        ]);
    }

    /**
     * All Opportunities (Admin)
     */
    public function allOpportunity(Request $req)
    {
        $user = Auth::user();

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Opportunity::with([
            'customer:id,name,email',
            'principal:id,name,email',
            'user:id,name,email',
        ])->whereColumn('id', 'parent_id'); // only head rows

        $query = $this->applyFilters($query, $req);

        $perPage = $req->per_page ?? 10;

        $data = $query->paginate($perPage);

        $this->attachListMeta($data);

        return response()->json([
            'status'  => 200,
            'message' => 'All opportunities fetched successfully',
            'data'    => $data,
        ]);
    }

    /**
     * Attach child item rows + counts to a paginated list of head rows
     */
    private function attachListMeta($data)
    {
        $data->getCollection()->transform(function ($parent) {
            $children = Opportunity::with('item:id,name,mat_code,part_model_no,description')
                ->where('parent_id', $parent->id)
                ->get();

            $parent->setAttribute('items', $children);
            $parent->setAttribute('total_items', $children->count());
            $parent->setAttribute(
                'pending_items',
                $children->where('item_status', 'Pending')->count()
            );

            return $parent;
        });
    }

    /**
     * Opportunity Details (head + items)
     */
    public function show($parentId)
    {
        $data = Opportunity::with([
            'item:id,name,mat_code,part_model_no,description',
            'customer:id,name,email',
            'principal:id,name,email',
        ])->where('parent_id', $parentId)->get();

        if ($data->isEmpty()) {
            return response()->json(['status' => 404, 'message' => 'Opportunity not found'], 404);
        }

        return response()->json([
            'status' => 200,
            'data'   => $data,
        ]);
    }

    /**
     * Update Opportunity head fields (applies to every item row under parent_id)
     */
    public function update(Request $req, $id)
    {
        try {
            $opportunity = Opportunity::find($id);

            if (!$opportunity) {
                return response()->json(['status' => 404, 'message' => 'Opportunity not found'], 404);
            }

            if ($opportunity->user_id != Auth::id() && Auth::user()->role !== 'admin') {
                return response()->json(['status' => 403, 'message' => 'Unauthorized'], 403);
            }

            $req->validate([
                'customer_id'      => 'sometimes|required|exists:users,id',
                'principal_id'     => 'nullable|exists:users,id',
                'quotation_ref'    => 'nullable|string|max:255',
                'details'          => 'nullable|string',
                'attachment'       => 'nullable|string|max:255',
                'status'           => 'sometimes|required|in:Open,In Progress,Converted,Lost',
                'opportunity_date' => 'nullable|date',
            ]);

            $oldData = $opportunity->toArray();

            $headFields = [
                'customer_id'      => $req->customer_id ?? $opportunity->customer_id,
                'principal_id'     => $req->principal_id ?? $opportunity->principal_id,
                'quotation_ref'    => $req->quotation_ref ?? $opportunity->quotation_ref,
                'details'          => $req->details ?? $opportunity->details,
                'attachment'       => $req->attachment ?? $opportunity->attachment,
                'status'           => $req->status ?? $opportunity->status,
                'opportunity_date' => $req->opportunity_date ?? $opportunity->opportunity_date,
                'updated_by'       => Auth::id(),
            ];

            // Apply head fields to every row that shares the same opportunity (parent_id)
            Opportunity::where('parent_id', $opportunity->parent_id ?? $opportunity->id)
                ->update($headFields);

            saveLog('UPDATE_OPPORTUNITY', 'Opportunity', [
                'old' => $oldData,
                'new' => $headFields,
            ]);

            return response()->json([
                'status'  => 200,
                'message' => 'Opportunity updated successfully',
                'data'    => $opportunity->fresh(),
            ]);

        } catch (ValidationException $e) {
            return response()->json(['status' => 422, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Opportunity update error: ' . $e->getMessage());
            return response()->json(['status' => 500, 'message' => 'Failed to update opportunity'], 500);
        }
    }

    /**
     * Add a single item row to an existing opportunity
     */
    public function addItem(Request $req, $parentId)
    {
        try {
            $head = Opportunity::where('id', $parentId)
                ->orWhere(function ($q) use ($parentId) {
                    $q->where('parent_id', $parentId);
                })
                ->first();

            if (!$head) {
                return response()->json(['status' => 404, 'message' => 'Opportunity not found'], 404);
            }

            $req->validate([
                'item_id'        => 'nullable|exists:items,id',
                'purchase_group' => 'nullable|string|max:255',
                'location'       => 'nullable|string|max:255',
                'quantity'       => 'nullable|integer|min:1',
                'date'           => 'nullable|date',
                'status'         => 'nullable|in:Pending,Quotation Placed,Completed',
            ]);

            $item = Opportunity::create([
                'parent_id'        => $head->parent_id ?? $head->id,
                'opportunity_code' => $head->opportunity_code,
                'opportunity_date' => $head->opportunity_date,

                'user_id'          => $head->user_id,
                'customer_id'      => $head->customer_id,
                'principal_id'     => $head->principal_id,
                'quotation_ref'    => $head->quotation_ref,
                'details'          => $head->details,
                'attachment'       => $head->attachment,

                'item_id'          => $req->item_id,
                'purchase_group'   => $req->purchase_group,
                'location'         => $req->location,
                'quantity'         => $req->quantity,
                'item_date'        => $req->date ?? now()->toDateString(),
                'item_status'      => $req->status ?? 'Pending',

                'status'           => $head->status,

                'created_by'       => Auth::id(),
                'updated_by'       => Auth::id(),
            ]);

            saveLog('ADD_OPPORTUNITY_ITEM', 'Opportunity', $item);

            return response()->json([
                'status'  => 200,
                'message' => 'Item added to opportunity successfully',
                'data'    => $item,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['status' => 422, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Opportunity add item error: ' . $e->getMessage());
            return response()->json(['status' => 500, 'message' => 'Failed to add item'], 500);
        }
    }

    /**
     * Update a single opportunity item row
     */
    public function updateItem(Request $req, $itemRowId)
    {
        try {
            $row = Opportunity::find($itemRowId);

            if (!$row) {
                return response()->json(['status' => 404, 'message' => 'Opportunity item not found'], 404);
            }

            $req->validate([
                'item_id'        => 'sometimes|nullable|exists:items,id',
                'purchase_group' => 'sometimes|nullable|string|max:255',
                'location'       => 'sometimes|nullable|string|max:255',
                'quantity'       => 'sometimes|nullable|integer|min:1',
                'date'           => 'sometimes|nullable|date',
                'status'         => 'sometimes|nullable|in:Pending,Quotation Placed,Completed',
            ]);

            $oldData = $row->toArray();

            $row->update([
                'item_id'        => $req->item_id ?? $row->item_id,
                'purchase_group' => $req->purchase_group ?? $row->purchase_group,
                'location'       => $req->location ?? $row->location,
                'quantity'       => $req->quantity ?? $row->quantity,
                'item_date'      => $req->date ?? $row->item_date,
                'item_status'    => $req->status ?? $row->item_status,
                'updated_by'     => Auth::id(),
            ]);

            saveLog('UPDATE_OPPORTUNITY_ITEM', 'Opportunity', ['old' => $oldData, 'new' => $row]);

            return response()->json([
                'status'  => 200,
                'message' => 'Opportunity item updated successfully',
                'data'    => $row,
            ]);

        } catch (ValidationException $e) {
            return response()->json(['status' => 422, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Opportunity item update error: ' . $e->getMessage());
            return response()->json(['status' => 500, 'message' => 'Failed to update item'], 500);
        }
    }

    /**
     * Delete a single opportunity item row
     */
    public function deleteItem($itemRowId)
    {
        try {
            $row = Opportunity::findOrFail($itemRowId);

            saveLog('DELETE_OPPORTUNITY_ITEM', 'Opportunity', $row);

            $row->delete();

            return response()->json(['status' => 200, 'message' => 'Opportunity item deleted successfully']);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 404, 'message' => 'Opportunity item not found'], 404);
        } catch (\Exception $e) {
            Log::error('Opportunity item delete error: ' . $e->getMessage());
            return response()->json(['status' => 500, 'message' => 'Failed to delete item'], 500);
        }
    }

    /**
     * Delete an entire Opportunity (head + all item rows)
     */
    public function destroy($id)
    {
        try {
            $opportunity = Opportunity::findOrFail($id);

            if ($opportunity->user_id != Auth::id() && Auth::user()->role !== 'admin') {
                return response()->json(['status' => 403, 'message' => 'Unauthorized'], 403);
            }

            $parentId = $opportunity->parent_id ?? $opportunity->id;

            saveLog('DELETE_OPPORTUNITY', 'Opportunity', ['parent_id' => $parentId]);

            Opportunity::where('parent_id', $parentId)->delete();

            return response()->json(['status' => 200, 'message' => 'Opportunity deleted successfully']);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 404, 'message' => 'Opportunity not found'], 404);
        } catch (\Exception $e) {
            Log::error('Opportunity deletion error: ' . $e->getMessage());
            return response()->json(['status' => 500, 'message' => 'Failed to delete opportunity. ' . $e->getMessage()], 500);
        }
    }

    /**
     * ───────────────────────────────────────────────────────────────────────
     *  OPPORTUNITY → ENQUIRY CONVERSION
     * ───────────────────────────────────────────────────────────────────────
     * Takes a set of opportunity item-row ids and turns them into Inquiry
     * (Enquiry) records, using the same parent/child item structure that
     * InquiryController::store() builds.
     *
     * Behaviour:
     *  - If `inquiry_id` (an existing inquiry_code, e.g. "INQ-00007") is
     *    supplied and matches an existing enquiry, the selected items are
     *    appended onto that enquiry's parent_id group.
     *  - Otherwise a brand new enquiry (inquiry_code) is generated and a new
     *    parent/child group of Inquiry rows is created.
     *  - Each converted Opportunity item row is stamped with
     *    `converted_inquiry_id` so it can't be converted twice, and the
     *    Opportunity head status flips to "Converted" once every item under
     *    it has been sent across.
     */
    public function convertToEnquiry(Request $req)
    {
        DB::beginTransaction();

        try {
            $req->validate([
                'opportunity_item_ids'   => 'required|array|min:1',
                'opportunity_item_ids.*' => 'required|exists:opportunities,id',

                'enquiry_type'     => 'required|string|max:100',
                'concerned_person' => 'required|string|max:255',
                'concerned_area'   => 'nullable|string|max:255',
                'status'           => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
                'date'             => 'nullable|date',

                // Optional: append to an existing enquiry instead of creating a new one
                'inquiry_id'       => 'nullable|string|max:100',
            ]);

            $itemRows = Opportunity::whereIn('id', $req->opportunity_item_ids)->get();

            if ($itemRows->isEmpty()) {
                DB::rollBack();
                return response()->json(['status' => 404, 'message' => 'No matching opportunity items found'], 404);
            }

            // Block items that were already converted
            $alreadyConverted = $itemRows->whereNotNull('converted_inquiry_id');
            if ($alreadyConverted->isNotEmpty()) {
                DB::rollBack();
                return response()->json([
                    'status'  => 422,
                    'message' => 'Some selected items have already been converted to an enquiry',
                    'ids'     => $alreadyConverted->pluck('id'),
                ], 422);
            }

            $firstRow = $itemRows->first();

            // ── Resolve / create the target enquiry parent group ──────────
            $existingInquiry = null;

            if ($req->inquiry_id) {
                $existingInquiry = Inquiry::where('inquiry_code', $req->inquiry_id)
                    ->whereColumn('id', 'parent_id') // head row
                    ->first();
            }

            if ($existingInquiry) {
                $inquiryCode = $existingInquiry->inquiry_code;
                $parentId    = $existingInquiry->parent_id ?? $existingInquiry->id;
            } else {
                $last = Inquiry::orderBy('id', 'desc')->first();

                $number = $last && $last->inquiry_code
                    ? intval(preg_replace('/\D/', '', $last->inquiry_code)) + 1
                    : 1;

                $inquiryCode = 'INQ' . str_pad($number, 5, '0', STR_PAD_LEFT);
                $parentId    = null;
            }

            $createdInquiries = [];

            foreach ($itemRows as $index => $oppItem) {

                $inquiry = Inquiry::create([
                    'parent_id'        => $parentId,
                    'inquiry_id'       => $req->inquiry_id ?? $inquiryCode,
                    'inquiry_code'     => $inquiryCode,
                    'inquiry_date'     => $req->date ?? now()->toDateString(),

                    'enquiry_type'     => $req->enquiry_type,
                    'customer_id'      => $firstRow->customer_id,

                    'concerned_person' => $req->concerned_person,
                    'concerned_area'   => $req->concerned_area,

                    'item_id'          => $oppItem->item_id,
                    'quantity'         => $oppItem->quantity,

                    'principal_id'     => $firstRow->principal_id,
                    'status'           => $req->status,

                    'source_opportunity_item_id' => $oppItem->id,

                    'user_id'          => Auth::id(),
                    'created_by'       => Auth::id(),
                    'updated_by'       => Auth::id(),
                ]);

                // First newly-created row becomes the parent for the rest
                if (!$parentId) {
                    $parentId = $inquiry->id;
                }

                $inquiry->update(['parent_id' => $parentId]);

                // Mark the opportunity item row as converted
                $oppItem->update([
                    'converted_inquiry_id' => $inquiry->id,
                    'item_status'          => 'Quotation Placed',
                    'updated_by'           => Auth::id(),
                ]);

                $createdInquiries[] = $inquiry->id;
            }

            // ── Flip the opportunity head status to "Converted" once every
            //    item row under it has been sent to an enquiry ────────────
            $oppParentId = $firstRow->parent_id ?? $firstRow->id;

            $remainingOpen = Opportunity::where('parent_id', $oppParentId)
                ->whereNull('converted_inquiry_id')
                ->count();

            if ($remainingOpen === 0) {
                Opportunity::where('parent_id', $oppParentId)->update([
                    'status'     => 'Converted',
                    'updated_by' => Auth::id(),
                ]);
            }

            DB::commit();

            saveLog('CONVERT_OPPORTUNITY_TO_ENQUIRY', 'Opportunity', [
                'opportunity_item_ids' => $req->opportunity_item_ids,
                'inquiry_code'         => $inquiryCode,
                'inquiry_parent_id'    => $parentId,
            ]);

            return response()->json([
                'status'        => 200,
                'message'       => 'Opportunity item(s) converted to enquiry successfully',
                'inquiry_code'  => $inquiryCode,
                'parent_id'     => $parentId,
                'inquiry_ids'   => $createdInquiries,
            ]);

        } catch (ValidationException $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Opportunity to Enquiry conversion error: ' . $e->getMessage());

            return response()->json([
                'status'  => 500,
                'message' => 'Failed to convert opportunity to enquiry. ' . $e->getMessage(),
            ], 500);
        }
    }
}