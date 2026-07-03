<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ItemController extends Controller
{
    /**
     * Create Item
     */
    public function store(Request $req)
    {
        try {
            $req->validate([
                'name'        => 'required|string|max:255',
                'mat_code'        => 'nullable|string|max:255',
                'part_model_no'        => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'status'      => 'nullable|in:active,inactive',
                'created_by'  => 'exists:users,id',
            ]);

            $itemId = DB::table('items')->insertGetId([
                'name'        => $req->name,
                'mat_code'        => $req->mat_code ?? '',
                'part_model_no'        => $req->part_model_no ?? '',
                'description' => $req->description,
                'status'      => $req->status ?? 'active',
                'created_by'      => $req->created_by,
                'created_at'  => now(),
                'updated_at'  => now()
            ]);

            $item = DB::table('items')->where('id', $itemId)->first();

            // Audit log
            saveLog('CREATE_ITEM', 'Item', $item);

            return response()->json([
                'status'=>200,
                'message' => 'Item created successfully',
                'data'    => $item
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Item creation error: '.$e->getMessage());
            return response()->json([
                'message' => 'Failed to create item. Please try again later.'
            ], 500);
        }
    }

    /**
     * COMMON FILTER FUNCTION
     */
    private function applyFilters($query, Request $req)
    {
        // Search
        if ($req->search) {
            $query->where(function ($q) use ($req) {
                $q->where('name', 'like', "%{$req->search}%")
                  ->orWhere('mat_code', 'like', "%{$req->search}%")
                  ->orWhere('part_model_no', 'like', "%{$req->search}%")
                  ->orWhere('description', 'like', "%{$req->search}%");
            });
        }

        // Status filter
        if ($req->status) {
            $query->where('status', $req->status);
        }
        if ($req->created_by) {
            $query->where('created_by', $req->created_by);
        }

        // Sorting
        $allowedSorts = ['id', 'name', 'status', 'created_at'];

        $sortBy = in_array($req->sort_by, $allowedSorts) ? $req->sort_by : 'created_at';
        $sortOrder = $req->sort_order === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    /**
     * Item List
     */
    public function index(Request $req)
    {
        $query = DB::table('items');

        $query = $this->applyFilters($query, $req);

        $perPage = $req->per_page ?? 10;

        $data = $query->paginate($perPage);

        return response()->json([
            'status'=>200,
            'message' => 'Item list fetch successfully',
            'data'    => $data
        ]);
    }

    /**
     * Single Item
     */
    public function show($id)
    {
        try {
            $item = DB::table('items')->where('id', $id)->first();

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            return response()->json([
                'status'=>200,
                'message' => 'Item details fetch successfully',
                'data'    => $item
            ]);

        } catch (\Exception $e) {
            Log::error('Item fetch error: '.$e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch item'
            ], 500);
        }
    }

    /**
     * Update Item
     */
    public function update(Request $req, $id)
    {
        try {
            $item = DB::table('items')->where('id', $id)->first();

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            $req->validate([
                'name'          => 'sometimes|required|string|max:255',
                'description'   => 'nullable|string',
                'mat_code'      => 'nullable|string|max:255',
                'part_model_no' => 'nullable|string|max:255',
                'status'        => 'sometimes|required|in:active,inactive',
                'created_by'    => 'sometimes|nullable|exists:users,id',
            ]);

            $oldData = (array) $item;

            DB::table('items')->where('id', $id)->update([
                'name'          => $req->name ?? $item->name,
                'description'   => $req->description ?? $item->description,
                'mat_code'      => $req->mat_code ?? $item->mat_code,
                'part_model_no' => $req->part_model_no ?? $item->part_model_no,
                'status'        => $req->status ?? $item->status,
                'created_by'    => $req->created_by ?? $item->created_by,
                'updated_at'    => now()
            ]);

            $updatedItem = DB::table('items')->where('id', $id)->first();

            // Audit log
            saveLog('UPDATE_ITEM', 'Item', [
                'old' => $oldData,
                'new' => $updatedItem
            ]);

            return response()->json([
                'status'=>200,
                'message' => 'Item updated successfully',
                'data'    => $updatedItem
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Item update error: '.$e->getMessage());
            return response()->json([
                'message' => 'Failed to update item'
            ], 500);
        }
    }

    /**
     * Delete Item
     */
    public function destroy($id)
    {
        try {
            $item = DB::table('items')->where('id', $id)->first();

            if (!$item) {
                return response()->json(['message' => 'Item not found'], 404);
            }

            // Audit log
            saveLog('DELETE_ITEM', 'Item', $item);

            DB::table('items')->where('id', $id)->delete();

            return response()->json([
                'status'=>200,
                'message' => 'Item deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Item delete error: '.$e->getMessage());
            return response()->json([
                'message' => 'Failed to delete item'
            ], 500);
        }
    }

    /**
     * Dropdown (UI use)
     */
    public function dropdown()
    {
        $items = DB::table('items')
            ->where('status', 'active')
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'message' => 'Item dropdown',
            'data'    => $items
        ]);
    }


}