<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

    protected $table = 'items';

    protected $fillable = [
        'name',
        'mat_code',
        'part_model_no',
        'description',
        'price',
        'status',
        'created_by',
        'updated_by'
    ];

    
}