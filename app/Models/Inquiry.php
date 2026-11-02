<?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Model;

// class Inquiry extends Model
// {
//     protected $fillable = [
//         'title',
//         'description',
//         'inquiry_id',
//         'parent_id',
//         'user_id',
//         'created_by',
//         'updated_by',
//         'enquiry_type',
//         'customer_id',
//         'concerned_person',
//         'concerned_area',
//         'inquiry_code',
//         'item_id',
//         'product_details',
//         'quantity',
//         'principal_id',
//         'status',
//     ];



//  User relation
// public function user()
// {
//     return $this->belongsTo(User::class);
// }
// public function customer()
// {
//     return $this->belongsTo(User::class, 'customer_id');
// }

// public function principal()
// {
//     return $this->belongsTo(User::class, 'principal_id');
// }


// Item relation 
// public function item()
// {
//     return $this->belongsTo(Item::class, 'item_id');
// }

// public function children()
// {
//     return $this->hasMany(Inquiry::class, 'parent_id', 'id');
// }

// public function parent()
// {
//     return $this->belongsTo(Inquiry::class, 'parent_id');
// }


// }


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    protected $fillable = [
        'title',
        'description',
        'inquiry_id',
        'parent_id',
        'user_id',
        'created_by',
        'updated_by',
        'enquiry_type',
        'customer_id',
        'concerned_person',
        'concerned_area',
        'inquiry_code',
        'item_id',
        'product_details',
        'quantity',
        'principal_id',
        'status',
        'price',
        'principal_quote_no',
        'principal_quote_date',
        'customer_quote_date',
        'quotation_no',
        'budgetary_no',
        'negotiation_details',
        'follow_notes',
        'discussion_dates',
        'revised_value',
        'customer_po_no',
        'customer_due_date',
        'customer_qty_order',
        'me_ref',
        'pending_qty',
        'customer_pdo_document',
        'po_principal_no',
        'promise_date',
        'total_qty_ordered_principal',
        'pending_qty_principal',
        'qty_received_principal',
        'released_date',
        'principal_po_document',
        // traceability back to the opportunity item this enquiry came from
        'source_opportunity_item_id',
        'converted_customer_inquiry_id',
        'source_principal_item_id',
        'url',
        'source_bo_id',
        'merged_source_ids',
        'linked_customer_quote_id',
        'inquiry_date',
        'received_via',
        'assigned_to',
        'short_desc',
        'ref',
        'enq_due',
        'priority',
        'purchase_invoice_no',
        'invoice_date',
        'principal_id',
        'gst_tax_amount',
        'payment_due_date',
        'pay_later_payment_date',
        'pay_later_status',
        'purchase_payment_status',
        'qty_invoiced',
        'unit_basic_price',
        'unit_purchase_price',
        'total_purchase_value',
        'po_amount',
        'po_basic',
        'cumulative',
        'po_date',
        'basic_amount',
        'pf_amount',
        'freight_amount',
        'po_amount_principal',
        'gst_amount',
        'total_with_gst',
        'basic_amount_purchase_invoice',
        'pf_amount_purchase_invoice',
        'freight_amount_purchase_invoice',
        'invoice_amount_purchase_invoice',
        'total_amount_purchase_invoice',
        'basic_purchase_invoice',
        'pf_purchase_invoice',
        'freight_purchase_invoice',
        'invoice_amount',
        'total_amount',
        'sale_basic',
        'sale_basic_cumulative',
        'sale_basic_gst',
        'sale_cumulative',
        'sales_invoice_no',
        'sales_invoice_date',
        'total_selling_value',
        // Commercial & CO fields
        'customer_po_date',
        'delivery_location',
        'customer_payment_terms',
        'ld_terms',
        'principal_co_no',
        'principal_co_date',
        'expected_delivery_date',
        'due_date_basis',
        'advance_received',
        'advance_adjusted',
        // Stage 10: Customer Invoice Submission / Portal Upload fields
        'portal_submission_required',
        'portal_submission_method',
        'portal_submission_status',
        'portal_submission_date',
        'portal_ack_ref',
        'portal_rejection_reason',
        'portal_resubmission_date',
        'portal_resubmission_remarks',
        'portal_supporting_docs',
        'portal_next_followup_date',
        'portal_responsible_person',
        // Stage 5: Principal Follow-up & Promise Date Revision
        'original_promise_date',
        'latest_followup_date',
        'next_followup_date',
        'principal_production_status',
        // Section 17: Advance Principal Order
        'is_advance_principal_order',
        'advance_order_reason',
        'expected_customer_name',
        'expected_customer_location',
        'expected_delivery_requirement',
        'unallocated_qty',
        // Section 16: Combined Orders
        'is_combined_principal_order',
        'combined_source_item_ids',
        // Section 20: Order Status and Formal Order Closure Checklist
        'order_closure_status',
        'closed_at',
        'closed_by',
        'closure_checklist_snapshot',
        'order_balance_cancelled_reason',
    ];

    protected $casts = [
        'merged_source_ids' => 'array',
        'combined_source_item_ids' => 'array',
        'closure_checklist_snapshot' => 'array',
        'is_advance_principal_order' => 'boolean',
        'is_combined_principal_order' => 'boolean',
        'original_promise_date' => 'date:Y-m-d',
        'latest_followup_date'  => 'date:Y-m-d',
        'next_followup_date'    => 'date:Y-m-d',
        'closed_at'             => 'datetime',
    ];

    //  User relation
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function principal()
    {
        return $this->belongsTo(User::class, 'principal_id');
    }

    // Item relation
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }


    public function children()
    {
        return $this->hasMany(Inquiry::class, 'parent_id', 'id');
    }

    public function parent()
    {
        return $this->belongsTo(Inquiry::class, 'parent_id');
    }

    // The Opportunity item row this enquiry was converted from (if any)
    public function sourceOpportunityItem()
    {
        return $this->belongsTo(Opportunity::class, 'source_opportunity_item_id');
    }


    public function shipmentBatches()
    {
        return $this->hasMany(MaterialShipmentBatch::class, 'inquiry_item_id')
            ->orderBy('batch_no');
    }

    // ── Payment Tracking ─────────────────────────────────────────────────
    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'inquiry_id')
            ->orderBy('created_at');
    }

    // ── Stage 5: Principal Follow-ups ─────────────────────────────────────
    public function principalFollowups()
    {
        return $this->hasMany(PrincipalFollowup::class, 'inquiry_id')
            ->orderBy('followup_date', 'desc')
            ->orderBy('id', 'desc');
    }
}