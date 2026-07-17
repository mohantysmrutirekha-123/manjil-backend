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
        'url',
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
}