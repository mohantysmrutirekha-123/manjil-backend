<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function dashboard()
    {
        $user = auth()->user();

        // Inquiry Query
        $inquiryQuery = DB::table('inquiries');

        // Role-based filter
        if ($user->role != 'admin') {
            $inquiryQuery->where('user_id', $user->id);
        }

        $totalInquiry = $inquiryQuery->count();

        $pendingInquiry = (clone $inquiryQuery)
            //->where('status', 'Pending')
            ->whereIn('status', ['Pending', 'Price Requested'])
            ->count();

        $completedInquiry = (clone $inquiryQuery)
            ->where('status', 'Closed')
            ->count();

        // Quotation Query
        $quotationQuery = DB::table('quotations');

        if ($user->role != 'admin') {
            $quotationQuery->where('created_by', $user->id);
        }

        $totalQuotation = $quotationQuery->count();

        $pendingQuotation = (clone $quotationQuery)
            ->where('status', 'Generated')
            ->count();

        $completedQuotation = (clone $quotationQuery)
            ->where('status', 'Sent to Customer')
            ->count();

        return response()->json([
            'status' => 200,
            'message' => 'Dashboard data fetched successfully',
            'data' => [
                'total_inquiry' => $totalInquiry,
                'pending_inquiry' => $pendingInquiry,
                'completed_inquiry' => $completedInquiry,

                'total_quotation' => $totalQuotation,
                'pending_quotation' => $pendingQuotation,
                'completed_quotation' => $completedQuotation,
            ]
        ]);
    }
}