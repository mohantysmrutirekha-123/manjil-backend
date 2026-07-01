<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Opportunity extends Model
{
    protected $fillable = [
        'parent_id',
        'opportunity_code',
        'opportunity_date',

        'user_id',

        'customer_id',
        'principal_id',

        'quotation_ref',
        'details',
        'attachment',

        'item_id',
        'purchase_group',
        'location',
        'quantity',
        'item_date',
        'item_status',

        'status',
        'converted_inquiry_id',

        'created_by',
        'updated_by',
    ];

    // Assigned user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function principal()
    {
        return $this->belongsTo(User::class, 'principal_id');
    }

    // Item master relation
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    // Parent / child (head + item rows), same pattern as Inquiry
    public function children()
    {
        return $this->hasMany(Opportunity::class, 'parent_id', 'id');
    }

    public function parent()
    {
        return $this->belongsTo(Opportunity::class, 'parent_id');
    }

    // The Inquiry row this opportunity item was converted into (if any)
    public function convertedInquiry()
    {
        return $this->belongsTo(Inquiry::class, 'converted_inquiry_id');
    }
}