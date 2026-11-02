<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrincipalFollowup extends Model
{
    use HasFactory;

    protected $table = 'principal_followups';

    protected $fillable = [
        'inquiry_id',
        'inquiry_code',
        'po_principal_no',
        'principal_id',
        'principal_name',
        'followup_date',
        'contact_person',
        'production_status',
        'response',
        'old_promise_date',
        'revised_promise_date',
        'is_promise_date_revised',
        'revision_reason',
        'next_followup_date',
        'responsible_person',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'followup_date'          => 'date:Y-m-d',
        'old_promise_date'       => 'date:Y-m-d',
        'revised_promise_date'   => 'date:Y-m-d',
        'next_followup_date'     => 'date:Y-m-d',
        'is_promise_date_revised' => 'boolean',
    ];

    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

