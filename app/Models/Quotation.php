<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $fillable = [
        //'inquiries_id',
        'inquiry_code',
        'quotation_code',
        'principal_price',
        'margin',
        'final_offer_price',
        'status',
        'created_by',
        'updated_by'
    ];

    // relation with inquiry
    

    public function inquiry()
{
    return $this->belongsTo(Inquiry::class, 'inquiry_code', 'inquiry_code');
}

}