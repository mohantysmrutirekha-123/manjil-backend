<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Sections 16, 17, 19, 20: Combined Orders, Advance Orders, Closure Checklist & Action Dashboards
     */
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // Section 17: Advance Principal Order (Customer PO Awaited)
            if (!Schema::hasColumn('inquiries', 'is_advance_principal_order')) {
                $table->boolean('is_advance_principal_order')->default(false)->after('principal_production_status');
            }
            if (!Schema::hasColumn('inquiries', 'advance_order_reason')) {
                $table->text('advance_order_reason')->nullable()->after('is_advance_principal_order');
            }
            if (!Schema::hasColumn('inquiries', 'expected_customer_name')) {
                $table->string('expected_customer_name', 255)->nullable()->after('advance_order_reason');
            }
            if (!Schema::hasColumn('inquiries', 'expected_customer_location')) {
                $table->string('expected_customer_location', 255)->nullable()->after('expected_customer_name');
            }
            if (!Schema::hasColumn('inquiries', 'expected_delivery_requirement')) {
                $table->string('expected_delivery_requirement', 255)->nullable()->after('expected_customer_location');
            }
            if (!Schema::hasColumn('inquiries', 'unallocated_qty')) {
                $table->decimal('unallocated_qty', 15, 2)->default(0)->after('expected_delivery_requirement');
            }

            // Section 16: Combined Order Multi-Customer PO to One Principal PO
            if (!Schema::hasColumn('inquiries', 'is_combined_principal_order')) {
                $table->boolean('is_combined_principal_order')->default(false)->after('unallocated_qty');
            }
            if (!Schema::hasColumn('inquiries', 'combined_source_item_ids')) {
                $table->json('combined_source_item_ids')->nullable()->after('is_combined_principal_order');
            }

            // Section 20: Order Status and Formal Order Closure Checklist
            if (!Schema::hasColumn('inquiries', 'order_closure_status')) {
                $table->string('order_closure_status', 50)->default('In Progress')->after('combined_source_item_ids');
            }
            if (!Schema::hasColumn('inquiries', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('order_closure_status');
            }
            if (!Schema::hasColumn('inquiries', 'closed_by')) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            }
            if (!Schema::hasColumn('inquiries', 'closure_checklist_snapshot')) {
                $table->json('closure_checklist_snapshot')->nullable()->after('closed_by');
            }
            if (!Schema::hasColumn('inquiries', 'order_balance_cancelled_reason')) {
                $table->text('order_balance_cancelled_reason')->nullable()->after('closure_checklist_snapshot');
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
                'is_advance_principal_order',
                'advance_order_reason',
                'expected_customer_name',
                'expected_customer_location',
                'expected_delivery_requirement',
                'unallocated_qty',
                'is_combined_principal_order',
                'combined_source_item_ids',
                'order_closure_status',
                'closed_at',
                'closed_by',
                'closure_checklist_snapshot',
                'order_balance_cancelled_reason',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('inquiries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

