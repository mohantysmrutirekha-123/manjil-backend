<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * GET AUDIT LOG LIST
     */
    public function index(Request $request)
    {
        try {

            $query = DB::table('audit_logs')->orderBy('id', 'desc');

            // ROLE BASED ACCESS
            if (auth()->user()->role != 'admin') {
                $query->where('user_id', auth()->id());
            }

            // FILTERS

            // Filter by action
            if ($request->filled('action')) {
                $query->where('action', $request->action);
            }

            // Filter by user_id (only admin should use this ideally)
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            // Filter by date range
            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('created_at', [
                    $request->from_date,
                    $request->to_date
                ]);
            }

            // Pagination
            $logs = $query->paginate(10);

            // JSON DECODE FIX
            $logs->getCollection()->transform(function ($log) {
                $log->data = json_decode($log->data, true);
                return $log;
            });

            return response()->json([
                'status' => 200,
                'message' => 'Audit logs fetched successfully',
                'data' => $logs
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch audit logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * REPORT API
     */
    public function report(Request $request)
    {
        try {

            $baseQuery = \DB::table('audit_logs');

            // ROLE BASED
            if (auth()->user()->role != 'admin') {
                $baseQuery->where('user_id', auth()->id());
            }

            // SUMMARY COUNTS
            $summary = [
                'total_logs' => (clone $baseQuery)->count(),

                'total_logins' => (clone $baseQuery)
                    ->where('action', 'USER_LOGIN')->count(),

                'failed_logins' => (clone $baseQuery)
                    ->where('action', 'LOGIN_FAILED')->count(),

                'user_registered' => (clone $baseQuery)
                    ->where('action', 'USER_REGISTER')->count(),

                'quotation_created' => (clone $baseQuery)
                    ->where('action', 'CREATE_QUOTATION')->count(),

                'quotation_updated' => (clone $baseQuery)
                    ->where('action', 'UPDATE_QUOTATION')->count(),

                'quotation_deleted' => (clone $baseQuery)
                    ->where('action', 'DELETE_QUOTATION')->count(),
            ];

            // DETAILED DATA (LATEST 5 RECORDS EACH)

            $details = [
                'recent_logins' => (clone $baseQuery)
                    ->where('action', 'USER_LOGIN')
                    ->orderBy('id', 'desc')
                    ->limit(5)
                    ->get(),

                'failed_login_details' => (clone $baseQuery)
                    ->where('action', 'LOGIN_FAILED')
                    ->orderBy('id', 'desc')
                    ->limit(5)
                    ->get(),

                'recent_registrations' => (clone $baseQuery)
                    ->where('action', 'USER_REGISTER')
                    ->orderBy('id', 'desc')
                    ->limit(5)
                    ->get(),

                'quotation_activity' => (clone $baseQuery)
                    ->whereIn('action', [
                        'CREATE_QUOTATION',
                        'UPDATE_QUOTATION',
                        'DELETE_QUOTATION'
                    ])
                    ->orderBy('id', 'desc')
                    ->limit(5)
                    ->get(),
            ];

            // JSON DECODE
            foreach ($details as $key => $records) {
                $details[$key] = $records->map(function ($item) {
                    $item->data = json_decode($item->data, true);
                    return $item;
                });
            }

            return response()->json([
                'status' => 200,
                'message' => 'Audit report fetched successfully',
                'data' => [
                    'summary' => $summary,
                    'details' => $details
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}