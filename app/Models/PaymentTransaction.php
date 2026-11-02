<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions'; // crm_ prefix applied automatically

    protected $fillable = [
        'inquiry_id',
        'invoice_type',
        'invoice_no',
        'amount',
        'tds_amount',
        'gst_tds_amount',
        'ld_deduction',
        'other_deductions',
        'deduction_reason',
        'advance_adjusted',
        'total_settled',
        'status',
        'transaction_date',
        'payment_mode',
        'reference_no',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'gst_tds_amount' => 'decimal:2',
        'ld_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'advance_adjusted' => 'decimal:2',
        'total_settled' => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }
}
