<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // Customer PO commercial fields
            if (!Schema::hasColumn('inquiries', 'customer_po_date')) {
                $table->date('customer_po_date')->nullable()->after('customer_po_no');
            }
            if (!Schema::hasColumn('inquiries', 'delivery_location')) {
                $table->string('delivery_location', 255)->nullable()->after('customer_due_date');
            }
            if (!Schema::hasColumn('inquiries', 'customer_payment_terms')) {
                $table->string('customer_payment_terms', 255)->nullable()->after('delivery_location');
            }
            if (!Schema::hasColumn('inquiries', 'ld_terms')) {
                $table->string('ld_terms', 255)->nullable()->after('customer_payment_terms');
            }

            // Principal PO & CO fields
            if (!Schema::hasColumn('inquiries', 'principal_co_no')) {
                $table->string('principal_co_no', 255)->nullable()->after('po_principal_no');
            }
            if (!Schema::hasColumn('inquiries', 'principal_co_date')) {
                $table->date('principal_co_date')->nullable()->after('principal_co_no');
            }
            if (!Schema::hasColumn('inquiries', 'expected_delivery_date')) {
                $table->date('expected_delivery_date')->nullable()->after('promise_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $cols = [
                'customer_po_date',
                'delivery_location',
                'customer_payment_terms',
                'ld_terms',
                'principal_co_no',
                'principal_co_date',
                'expected_delivery_date',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('inquiries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

