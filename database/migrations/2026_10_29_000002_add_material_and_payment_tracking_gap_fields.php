<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add gap fields to crm_material_shipment_batches
        Schema::table('material_shipment_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('material_shipment_batches', 'condition')) {
                $table->string('condition', 50)->default('Accepted')->after('status');
            }
            if (!Schema::hasColumn('material_shipment_batches', 'actual_receipt_date')) {
                $table->date('actual_receipt_date')->nullable()->after('expected_delivery');
            }
            if (!Schema::hasColumn('material_shipment_batches', 'packages_count')) {
                $table->string('packages_count', 100)->nullable()->after('qty');
            }
            if (!Schema::hasColumn('material_shipment_batches', 'lr_date')) {
                $table->date('lr_date')->nullable()->after('ref_doc_no');
            }
            if (!Schema::hasColumn('material_shipment_batches', 'allocated_customer_po')) {
                $table->string('allocated_customer_po', 255)->nullable()->after('location');
            }
            if (!Schema::hasColumn('material_shipment_batches', 'allocated_qty')) {
                $table->decimal('allocated_qty', 10, 2)->nullable()->after('allocated_customer_po');
            }
        });

        // 2. Add gap fields to crm_payment_transactions
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_transactions', 'tds_amount')) {
                $table->decimal('tds_amount', 10, 2)->default(0.00)->after('amount');
            }
            if (!Schema::hasColumn('payment_transactions', 'gst_tds_amount')) {
                $table->decimal('gst_tds_amount', 10, 2)->default(0.00)->after('tds_amount');
            }
            if (!Schema::hasColumn('payment_transactions', 'ld_deduction')) {
                $table->decimal('ld_deduction', 10, 2)->default(0.00)->after('gst_tds_amount');
            }
            if (!Schema::hasColumn('payment_transactions', 'other_deductions')) {
                $table->decimal('other_deductions', 10, 2)->default(0.00)->after('ld_deduction');
            }
            if (!Schema::hasColumn('payment_transactions', 'deduction_reason')) {
                $table->text('deduction_reason')->nullable()->after('other_deductions');
            }
            if (!Schema::hasColumn('payment_transactions', 'advance_adjusted')) {
                $table->decimal('advance_adjusted', 10, 2)->default(0.00)->after('deduction_reason');
            }
            if (!Schema::hasColumn('payment_transactions', 'total_settled')) {
                $table->decimal('total_settled', 10, 2)->default(0.00)->after('advance_adjusted');
            }
        });

        // 3. Add gap fields to crm_inquiries
        Schema::table('inquiries', function (Blueprint $table) {
            if (!Schema::hasColumn('inquiries', 'due_date_basis')) {
                $table->string('due_date_basis', 50)->nullable()->default('Invoice Date')->after('payment_due_date');
            }
            if (!Schema::hasColumn('inquiries', 'advance_received')) {
                $table->decimal('advance_received', 12, 2)->default(0.00)->after('po_amount');
            }
            if (!Schema::hasColumn('inquiries', 'advance_adjusted')) {
                $table->decimal('advance_adjusted', 12, 2)->default(0.00)->after('advance_received');
            }
        });
    }

    public function down(): void
    {
        Schema::table('material_shipment_batches', function (Blueprint $table) {
            $table->dropColumn([
                'condition', 'actual_receipt_date', 'packages_count',
                'lr_date', 'allocated_customer_po', 'allocated_qty'
            ]);
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'tds_amount', 'gst_tds_amount', 'ld_deduction',
                'other_deductions', 'deduction_reason', 'advance_adjusted', 'total_settled'
            ]);
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn(['due_date_basis', 'advance_received', 'advance_adjusted']);
        });
    }
};

