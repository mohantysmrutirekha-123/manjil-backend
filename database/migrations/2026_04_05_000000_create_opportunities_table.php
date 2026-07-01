<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();

            // Parent / child pattern (same as inquiries) — one opportunity
            // "head" record per opportunity_code, one row per item.
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->string('opportunity_code')->nullable();   // e.g. OPP-001
            $table->date('opportunity_date')->nullable();

            // Who the opportunity is assigned to
            $table->unsignedBigInteger('user_id')->nullable();

            // Customer / Principal
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('principal_id')->nullable();

            // Reference / details
            $table->string('quotation_ref')->nullable();
            $table->text('details')->nullable();
            $table->string('attachment')->nullable();

            // Item line (per row, like inquiries.item_id)
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('purchase_group')->nullable();
            $table->string('location')->nullable();
            $table->integer('quantity')->nullable();
            $table->date('item_date')->nullable();
            $table->string('item_status')->nullable(); // Pending / Quotation Placed / Completed

            // Head-level status
            $table->string('status')->default('Open'); // Open / In Progress / Converted / Lost

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};