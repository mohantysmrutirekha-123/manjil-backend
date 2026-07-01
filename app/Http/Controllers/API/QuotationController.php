<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Inquiry;
use App\Models\Quotation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class QuotationController extends Controller
{
    /**
     * CREATE Quotation
     */
    // public function store(Request $req)
    // {
    //     try {
    //         $req->validate([
    //             'inquiries_id'    => 'required|exists:inquiries,id',
    //             'principal_price' => 'required|numeric',
    //             'margin'          => 'required|numeric',
    //             'status'          => 'required|in:Generated,Sent to Customer,Negotiation'
    //         ]);

    //         $finalPrice = $req->principal_price + ($req->principal_price * $req->margin / 100);

    //         $quotation = Quotation::create([
    //             'inquiries_id'     => $req->inquiries_id,
    //             'principal_price'  => $req->principal_price,
    //             'margin'           => $req->margin,
    //             'final_offer_price'=> $finalPrice,
    //             'status'           => $req->status,
    //             'created_by'       => Auth::id(),
    //             'updated_by'       => Auth::id()
    //         ]);

    //         // Audit log
    //         saveLog('CREATE_QUOTATION', 'Quotation', $quotation);

    //         return response()->json([
    //             'message' => 'Quotation created successfully',
    //             'data'    => $quotation
    //         ], 201);

    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'message' => 'Validation failed',
    //             'errors'  => $e->errors()
    //         ], 422);

    //     } catch (\Exception $e) {
    //         Log::error('Quotation creation error: '.$e->getMessage());
    //         return response()->json([
    //             'message' => 'Failed to create quotation. Please try again later.'
    //         ], 500);
    //     }
    // }

    public function store(Request $req)
    {
        try {
            $req->validate([
                'inquiry_code'   => 'required|exists:inquiries,inquiry_code',
                'principal_price'=> 'required|numeric',
                'margin'         => 'required|numeric',
                'status'         => 'required|in:Generated,Sent to Customer,Negotiation'
            ]);

            // 🔍 Get Inquiry using code
            $inquiry = Inquiry::where('inquiry_code', $req->inquiry_code)->first();

            if (!$inquiry) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Invalid inquiry code'
                    ], 404);
            }

            // Final Price
            $finalPrice = $req->principal_price + ($req->principal_price * $req->margin / 100);

            // Generate Quotation Code (QT001)
            $last = Quotation::orderBy('id', 'desc')->first();

            $number = $last && $last->quotation_code
                ? intval(substr($last->quotation_code, 2)) + 1
                : 1;

            $quotationCode = 'QT' . str_pad($number, 3, '0', STR_PAD_LEFT);

            // Create
            $quotation = Quotation::create([
                'quotation_code'    => $quotationCode,
                'inquiry_code'      => $req->inquiry_code,
                'principal_price'   => $req->principal_price,
                'margin'            => $req->margin,
                'final_offer_price' => $finalPrice,
                'status'            => $req->status,
                'created_by'        => Auth::id(),
                'updated_by'        => Auth::id()
            ]);

            saveLog('CREATE_QUOTATION', 'Quotation', $quotation);

            return response()->json([
                'status' => 200,
                'message' => 'Quotation created successfully',
                'data'    => $quotation
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'status' =>422,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Quotation creation error: '.$e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to create quotation'
            ], 500);
        }
    }

    /**
     * COMMON FILTER FUNCTION (REUSABLE)
     */
    private function applyFilters($query, Request $req)
    {
        if ($req->filled('search')) {
            $search = $req->search;
            $query->where(function ($q) use ($search) {
                $q->where('status', 'like', "%{$search}%")
                  ->orWhere('principal_price', 'like', "%{$search}%")
                  ->orWhere('margin', 'like', "%{$search}%")
                  ->orWhere('final_offer_price', 'like', "%{$search}%");
            });
        }

        if ($req->filled('from_date') && $req->filled('to_date')) {
            $query->whereBetween('created_at', [
                $req->from_date . ' 00:00:00',
                $req->to_date . ' 23:59:59'
            ]);
        }

        $allowedSorts = ['id', 'principal_price', 'margin', 'status', 'created_at'];
        $sortBy = in_array($req->sort_by, $allowedSorts) ? $req->sort_by : 'created_at';
        $sortOrder = $req->sort_order === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    /**
     * MY QUOTATION (USER)
     */
    public function myQuotation(Request $req)
    {
        try {
            $query = Quotation::where('created_by', Auth::id());
            $query = $this->applyFilters($query, $req);
            $perPage = $req->per_page ?? 10;
            $data = $query->paginate($perPage);

            return response()->json([
                'message' => 'My quotations',
                'data'    => $data
            ]);

        } catch (\Exception $e) {
            Log::error('My quotations error: '.$e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch quotations'
            ], 500);
        }
    }

    /**
     * ALL QUOTATION (ADMIN)
     */
    public function allQuotation(Request $req)
    {
        try {
            $user = Auth::user();
            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            //$query = Quotation::with('inquiry:id,enquiry_type,concerned_person');
             $query = Quotation::with([
                'inquiry' => function ($q) {
                    $q->select('inquiry_code','enquiry_type','concerned_person','item_id')
                    ->with('item:id,name,description');
                }
            ]);
            $query = $this->applyFilters($query, $req);
            $perPage = $req->per_page ?? 10;
            $data = $query->paginate($perPage);

            return response()->json([
                'status' => 200,
                'message' => 'All quotations fetch successfully',
                'data'    => $data
            ]);

        } catch (\Exception $e) {
            Log::error('All quotations error: '.$e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to fetch quotations'
            ], 500);
        }
    }

    /**
     * UPDATE Quotation
     */
    public function update(Request $req, $id)
    {
        try {
            $quotation = Quotation::find($id);
            if (!$quotation) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Quotation not found'
                    ], 404);
            }

            $req->validate([
                'principal_price' => 'sometimes|required|numeric',
                'margin'          => 'sometimes|required|numeric',
                'status'          => 'sometimes|required|in:Generated,Sent to Customer,Negotiation'
            ]);

            $principal = $req->principal_price ?? $quotation->principal_price;
            $margin    = $req->margin ?? $quotation->margin;
            $finalPrice = $principal + ($principal * $margin / 100);

            $oldData = $quotation->toArray();

            $quotation->update([
                'principal_price'   => $principal,
                'margin'            => $margin,
                'final_offer_price' => $finalPrice,
                'status'            => $req->status ?? $quotation->status,
                'updated_by'        => Auth::id()
            ]);

            saveLog('UPDATE_QUOTATION', 'Quotation', [
                'old' => $oldData,
                'new' => $quotation
            ]);

            return response()->json([
                'message' => 'Quotation updated successfully',
                'data'    => $quotation
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Quotation update error: '.$e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update quotation. Please try again later.'
            ], 500);
        }
    }

    /**
     * DELETE Quotation
     */
    public function destroy($id)
    {
        try {
            $quotation = Quotation::find($id);
            if (!$quotation) {
                return response()->json(['message' => 'Quotation not found'], 404);
            }

            //Audit Log
            saveLog('DELETE_QUOTATION', 'Quotation', $quotation);

            $quotation->delete();

            return response()->json([
                'status' =>200,
                'message' => 'Quotation deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Quotation deletion error: '.$e->getMessage());
            return response()->json([
                'message' => 'Failed to delete quotation. Please try again later.'
            ], 500);
        }
    }

}