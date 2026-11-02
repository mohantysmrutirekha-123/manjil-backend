<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialShipmentBatch extends Model
{
    protected $table = 'material_shipment_batches'; // crm_ prefix applied automatically

    protected $fillable = [
        'inquiry_item_id',
        'po_principal_no',
        'batch_no',
        'qty',
        'packages_count',
        'status',
        'condition',
        'dispatch_date',
        'expected_delivery',
        'actual_receipt_date',
        'ref_doc_type',
        'ref_doc_no',
        'lr_date',
        'location',
        'allocated_customer_po',
        'allocated_qty',
        'remarks',
        'transporter',
        'invoice_no',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty' => 'decimal:2',
        'allocated_qty' => 'decimal:2',
        'dispatch_date' => 'date',
        'expected_delivery' => 'date',
        'actual_receipt_date' => 'date',
        'lr_date' => 'date',
        'invoice_no' => 'string',
    ];

    public function inquiryItem()
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_item_id');
    }
}
