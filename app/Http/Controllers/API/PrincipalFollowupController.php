<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\PrincipalFollowup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PrincipalFollowupController extends Controller
{
    /**
     * Get follow-up timeline & promise date history for a specific inquiry or principal PO
     */
    public function index(Request $request, $inquiry_id)
    {
        try {
            $inquiry = Inquiry::with(['principal', 'customer'])->findOrFail($inquiry_id);

            // Fetch follow-ups linked to this inquiry or sharing the same principal PO
            $query = PrincipalFollowup::query();
            if ($inquiry->po_principal_no) {
                $query->where(function ($q) use ($inquiry) {
                    $q->where('inquiry_id', $inquiry->id)
                      ->orWhere('po_principal_no', $inquiry->po_principal_no);
                });
            } else {
                $query->where('inquiry_id', $inquiry->id);
            }

            $followups = $query->orderBy('followup_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            // Extract revisions history (where promise date was changed)
            $revisions = $followups->where('is_promise_date_revised', true)->values();

            $originalPromiseDate = $inquiry->original_promise_date 
                ?? ($revisions->count() > 0 ? $revisions->last()->old_promise_date : $inquiry->promise_date);

            $currentPromiseDate = $inquiry->promise_date;
            $customerDueDate = $inquiry->customer_due_date;

            // Compute delay risk against customer due date
            $isOverdue = false;
            $delayDays = 0;
            if ($currentPromiseDate && $customerDueDate) {
                $cp = Carbon::parse($currentPromiseDate);
                $cd = Carbon::parse($customerDueDate);
                if ($cp->gt($cd)) {
                    $isOverdue = true;
                    $delayDays = $cd->diffInDays($cp);
                }
            }

            return response()->json([
                'status' => true,
                'data'   => [
                    'inquiry' => [
                        'id'                          => $inquiry->id,
                        'inquiry_code'                => $inquiry->inquiry_code,
                        'po_principal_no'             => $inquiry->po_principal_no,
                        'principal_name'              => optional($inquiry->principal)->name ?? $inquiry->principal_name,
                        'customer_name'               => optional($inquiry->customer)->name ?? $inquiry->customer_name,
                        'customer_due_date'           => $customerDueDate ? Carbon::parse($customerDueDate)->format('Y-m-d') : null,
                        'original_promise_date'       => $originalPromiseDate ? Carbon::parse($originalPromiseDate)->format('Y-m-d') : null,
                        'current_promise_date'        => $currentPromiseDate ? Carbon::parse($currentPromiseDate)->format('Y-m-d') : null,
                        'latest_followup_date'        => $inquiry->latest_followup_date ? Carbon::parse($inquiry->latest_followup_date)->format('Y-m-d') : null,
                        'next_followup_date'          => $inquiry->next_followup_date ? Carbon::parse($inquiry->next_followup_date)->format('Y-m-d') : null,
                        'principal_production_status' => $inquiry->principal_production_status ?? 'Under Production',
                        'revisions_count'             => $revisions->count(),
                        'is_at_delay_risk'            => $isOverdue,
                        'delay_days'                  => $delayDays,
                    ],
                    'followups' => $followups,
                    'revisions' => $revisions,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch follow-up history: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a new follow-up interaction and optionally revise the promise date
     */
    public function store(Request $request, $inquiry_id)
    {
        DB::beginTransaction();
        try {
            $inquiry = Inquiry::findOrFail($inquiry_id);

            $validated = $request->validate([
                'followup_date'        => 'nullable|date',
                'contact_person'       => 'nullable|string|max:255',
                'production_status'    => 'nullable|string|max:50',
                'response'             => 'nullable|string',
                'revised_promise_date' => 'nullable|date',
                'revision_reason'      => 'nullable|string',
                'next_followup_date'   => 'nullable|date',
                'responsible_person'   => 'nullable|string|max:255',
                'remarks'              => 'nullable|string',
            ]);

            $oldPromiseDate = $inquiry->promise_date;
            $newPromiseDate = !empty($validated['revised_promise_date']) 
                ? Carbon::parse($validated['revised_promise_date'])->format('Y-m-d') 
                : null;

            // Check if promise date is being changed
            $isRevised = false;
            if ($newPromiseDate && $newPromiseDate !== ($oldPromiseDate ? Carbon::parse($oldPromiseDate)->format('Y-m-d') : null)) {
                $isRevised = true;
            }

            // Create follow-up log record
            $followup = PrincipalFollowup::create([
                'inquiry_id'              => $inquiry->id,
                'inquiry_code'            => $inquiry->inquiry_code,
                'po_principal_no'         => $inquiry->po_principal_no,
                'principal_id'            => $inquiry->principal_id,
                'principal_name'          => optional($inquiry->principal)->name ?? $inquiry->principal_name,
                'followup_date'           => $validated['followup_date'] ?? now()->toDateString(),
                'contact_person'          => $validated['contact_person'] ?? null,
                'production_status'       => $validated['production_status'] ?? 'Under Production',
                'response'                => $validated['response'] ?? null,
                'old_promise_date'        => $oldPromiseDate,
                'revised_promise_date'    => $isRevised ? $newPromiseDate : null,
                'is_promise_date_revised' => $isRevised,
                'revision_reason'         => $isRevised ? ($validated['revision_reason'] ?? 'Supplier commitment revised during follow-up') : null,
                'next_followup_date'      => $validated['next_followup_date'] ?? null,
                'responsible_person'      => $validated['responsible_person'] ?? (Auth::user()?->name ?? 'Operations Team'),
                'remarks'                 => $validated['remarks'] ?? null,
                'created_by'              => Auth::id(),
            ]);

            // Update matching inquiry items across the same PO batch
            $matchQuery = Inquiry::query();
            if ($inquiry->po_principal_no) {
                $matchQuery->where('po_principal_no', $inquiry->po_principal_no);
            } else {
                $matchQuery->where('id', $inquiry->id);
            }

            $updatePayload = [
                'latest_followup_date'        => $validated['followup_date'] ?? now()->toDateString(),
                'next_followup_date'          => $validated['next_followup_date'] ?? null,
                'principal_production_status' => $validated['production_status'] ?? 'Under Production',
                'updated_at'                  => now(),
            ];

            if ($isRevised) {
                // If original_promise_date was never set, preserve the original one before changing
                if (empty($inquiry->original_promise_date)) {
                    $updatePayload['original_promise_date'] = $oldPromiseDate ?? $newPromiseDate;
                }
                $updatePayload['promise_date'] = $newPromiseDate;
            }

            $affected = $matchQuery->update($updatePayload);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => "Principal follow-up recorded successfully ({$affected} PO item(s) updated).",
                'data'    => [
                    'followup'        => $followup,
                    'is_date_revised' => $isRevised,
                    'new_promise_date'=> $newPromiseDate ?? $oldPromiseDate,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to record principal follow-up: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a daily/weekly list of principal follow-ups due or overdue
     */
    public function dueFollowups(Request $request)
    {
        try {
            $today = Carbon::today()->format('Y-m-d');
            $period = $request->query('period', 'today'); // 'today', 'week', 'all'

            $query = Inquiry::whereNotNull('next_followup_date')
                ->whereIn('enquiry_type', ['Purchase_Order_To_Principal', 'PO_To_Principal', 'Material_Tracking']);

            if ($period === 'today') {
                $query->where('next_followup_date', '<=', $today);
            } elseif ($period === 'week') {
                $nextWeek = Carbon::today()->addDays(7)->format('Y-m-d');
                $query->whereBetween('next_followup_date', [$today, $nextWeek]);
            }

            $items = $query->with(['principal', 'customer'])
                ->orderBy('next_followup_date', 'asc')
                ->get();

            // Group by po_principal_no or inquiry_code to avoid duplicates for multi-item POs
            $grouped = $items->groupBy(function ($item) {
                return $item->po_principal_no ?: 'ENQ_' . $item->inquiry_code;
            })->map(function ($group) use ($today) {
                $first = $group->first();
                $nextDate = Carbon::parse($first->next_followup_date);
                $isOverdue = $nextDate->lt(Carbon::today());
                $daysDiff = Carbon::today()->diffInDays($nextDate, false);

                return [
                    'inquiry_id'                  => $first->id,
                    'inquiry_code'                => $first->inquiry_code,
                    'po_principal_no'             => $first->po_principal_no,
                    'principal_name'              => optional($first->principal)->name ?? $first->principal_name,
                    'customer_name'               => optional($first->customer)->name ?? $first->customer_name,
                    'customer_due_date'           => $first->customer_due_date ? Carbon::parse($first->customer_due_date)->format('Y-m-d') : null,
                    'promise_date'                => $first->promise_date ? Carbon::parse($first->promise_date)->format('Y-m-d') : null,
                    'latest_followup_date'        => $first->latest_followup_date ? Carbon::parse($first->latest_followup_date)->format('Y-m-d') : null,
                    'next_followup_date'          => $first->next_followup_date ? Carbon::parse($first->next_followup_date)->format('Y-m-d') : null,
                    'principal_production_status' => $first->principal_production_status ?? 'Under Production',
                    'is_overdue'                  => $isOverdue,
                    'days_overdue'                => $isOverdue ? abs($daysDiff) : 0,
                    'items_count'                 => $group->count(),
                ];
            })->values();

            return response()->json([
                'status'     => true,
                'count'      => $grouped->count(),
                'data'       => $grouped,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch due follow-ups: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get standalone Promise Date Revision History audit trail
     */
    public function promiseDateHistory($inquiry_id)
    {
        try {
            $inquiry = Inquiry::findOrFail($inquiry_id);

            $revisions = PrincipalFollowup::where('is_promise_date_revised', true)
                ->where(function ($q) use ($inquiry) {
                    $q->where('inquiry_id', $inquiry->id);
                    if ($inquiry->po_principal_no) {
                        $q->orWhere('po_principal_no', $inquiry->po_principal_no);
                    }
                })
                ->orderBy('followup_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $originalDate = $inquiry->original_promise_date 
                ?? ($revisions->count() > 0 ? $revisions->first()->old_promise_date : $inquiry->promise_date);

            return response()->json([
                'status' => true,
                'data'   => [
                    'original_promise_date' => $originalDate ? Carbon::parse($originalDate)->format('Y-m-d') : null,
                    'current_promise_date'  => $inquiry->promise_date ? Carbon::parse($inquiry->promise_date)->format('Y-m-d') : null,
                    'customer_due_date'     => $inquiry->customer_due_date ? Carbon::parse($inquiry->customer_due_date)->format('Y-m-d') : null,
                    'total_revisions'       => $revisions->count(),
                    'history'               => $revisions,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch promise date history: ' . $e->getMessage(),
            ], 500);
        }
    }
}

