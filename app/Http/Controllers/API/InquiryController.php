<?php
// app\Http\Controllers\API\InquiryController.php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

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
            'enquiry_type'     => 'required|string|max:100',
            'inquiry_id'       => 'required|string|max:100',
            'customer_id'      => 'required|exists:users,id',
            'concerned_person' => 'required|string|max:255',
            'concerned_area'   => 'nullable|string|max:255',
            'principal_id'     => 'required|exists:users,id',
            'status'           => 'required|in:Price Requested,Pending,Closed,Completed,Regret',
            'date'             => 'required|date',
            'items'            => 'required|array|min:1',
            'items.*.item_id'  => 'required|exists:items,id',
            'items.*.quantity' => 'required|integer|min:1'
        ]);

        $last = Inquiry::orderBy('id', 'desc')->first();

        $number = $last && $last->inquiry_code
            ? intval(substr($last->inquiry_code, 3)) + 1
            : 1;

        $inquiryCode = 'INQ' . str_pad($number, 5, '0', STR_PAD_LEFT);

        $parentId = null;

        foreach ($req->items as $index => $item) {

            $inquiry = Inquiry::create([
                'parent_id'        => null,
                'inquiry_id'     => $req->inquiry_id,
                'inquiry_code'     => $inquiryCode,
                'inquiry_date'     => $req->date,

                'enquiry_type'     => $req->enquiry_type,
                'customer_id'      => $req->customer_id,

                'concerned_person' => $req->concerned_person,
                'concerned_area'   => $req->concerned_area,

                'item_id'          => $item['item_id'],
                'quantity'         => $item['quantity'],

                'principal_id'     => $req->principal_id,
                'status'           => $req->status,

                'user_id'          => Auth::id(),
                'created_by'       => Auth::id(),
                'updated_by'       => Auth::id()
            ]);

            if ($index == 0) {

                $parentId = $inquiry->id;

                $inquiry->update([
                    'parent_id' => $parentId
                ]);
            } else {

                $inquiry->update([
                    'parent_id' => $parentId
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'status' => 200,
            'message' => 'Inquiry created successfully',
            'inquiry_code' => $inquiryCode,
            'parent_id' => $parentId
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

        Log::error('Inquiry create error : ' . $e->getMessage());

        return response()->json([
            'status' => 500,
            'message' => 'Failed to create inquiry'
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
                  ->orWhere('concerned_person', 'like', "%{$req->search}%")
                  ->orWhere('concerned_area', 'like', "%{$req->search}%")
                  ->orWhere('product_details', 'like', "%{$req->search}%");
            });
        }

        // Date filter
        if ($req->from_date && $req->to_date) {
            $query->whereBetween('created_at', [
                $req->from_date . ' 00:00:00',
                $req->to_date . ' 23:59:59'
            ]);
        }

        // Sorting
        $allowedSorts = ['id', 'enquiry_type', 'status', 'created_at'];

        $sortBy = in_array($req->sort_by, $allowedSorts) ? $req->sort_by : 'created_at';
        $sortOrder = $req->sort_order === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        return $query;
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

      /**
     * Update Inquiry
     */
   
      public function update(Request $req, $id)
    {
        try {
            $inquiry = Inquiry::find($id);
            if (!$inquiry) {
                return response()->json([
                    'status'=> 404,
                    'message' => 'Inquiry not found'
                    ], 404);
            }

            if ($inquiry->user_id != Auth::id() && Auth::user()->role !== 'admin') {
                return response()->json([
                    'status' => '403',
                    'message' => 'Unauthorized'
                    ], 403);
            }

            $req->validate([
                'enquiry_type'     => 'sometimes|required|string|max:100',
                'customer_ids'     => 'sometimes|required|array',
                'customer_ids.*'   => 'exists:users,id',
                'item_id' => 'sometimes|required|exists:items,id',
                'concerned_person' => 'sometimes|required|string|max:255',
                'concerned_area'   => 'nullable|string|max:255',
                'product_details'  => 'sometimes|required|string',
                'quantity'         => 'sometimes|required|integer|min:1',
                'principal_id'     => 'sometimes|required|integer',
                'status'           => 'required|in:Price Requested,Pending,Closed,Completed,Regret'
            ]);

            $oldData =  $inquiry->toArray();

            $inquiry->update([
                'enquiry_type'     => $req->enquiry_type ?? $inquiry->enquiry_type,
                'customer_ids'     => $req->customer_ids ?? $inquiry->customer_ids,
                'item_id'          => $req->item_id ?? $inquiry->item_id,
                'concerned_person' => $req->concerned_person ?? $inquiry->concerned_person,
                'concerned_area'   => $req->concerned_area ?? $inquiry->concerned_area,
                'product_details'  => $req->product_details ?? $inquiry->product_details,
                'quantity'         => $req->quantity ?? $inquiry->quantity,
                'principal_id'     => $req->principal_id ?? $inquiry->principal_id,
                'status'           => $req->status ?? $inquiry->status,
                'updated_by'       => Auth::id()
            ]);

            // Audit log
            saveLog('UPDATE_INQUIRY', 'Inquiry', [
                'old' => $oldData,
                'new' => $inquiry
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Inquiry updated successfully',
                'data'    => $inquiry
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
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


}