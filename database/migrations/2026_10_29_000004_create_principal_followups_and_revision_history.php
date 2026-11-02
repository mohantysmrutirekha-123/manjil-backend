<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stage 5: Principal Follow-up Log & Promise Date Revision History
     */
    public function up(): void
    {
        // 1. Add follow-up cache & original promise date to inquiries table
        Schema::table('inquiries', function (Blueprint $table) {
            if (!Schema::hasColumn('inquiries', 'original_promise_date')) {
                $table->date('original_promise_date')->nullable()->after('promise_date');
            }
            if (!Schema::hasColumn('inquiries', 'latest_followup_date')) {
                $table->date('latest_followup_date')->nullable()->after('original_promise_date');
            }
            if (!Schema::hasColumn('inquiries', 'next_followup_date')) {
                $table->date('next_followup_date')->nullable()->after('latest_followup_date');
            }
            if (!Schema::hasColumn('inquiries', 'principal_production_status')) {
                $table->string('principal_production_status', 50)->nullable()->after('next_followup_date');
            }
        });

        // 2. Create dedicated crm_principal_followups table for complete historical audit trail
        if (!Schema::hasTable('principal_followups')) {
            Schema::create('principal_followups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('inquiry_id')->index();
                $table->string('inquiry_code', 100)->nullable()->index();
                $table->string('po_principal_no', 100)->nullable()->index();
                $table->unsignedBigInteger('principal_id')->nullable();
                $table->string('principal_name', 255)->nullable();
                $table->date('followup_date');
                $table->string('contact_person', 255)->nullable();
                $table->string('production_status', 50)->default('Under Production');
                $table->text('response')->nullable();
                $table->date('old_promise_date')->nullable();
                $table->date('revised_promise_date')->nullable();
                $table->boolean('is_promise_date_revised')->default(false);
                $table->text('revision_reason')->nullable();
                $table->date('next_followup_date')->nullable()->index();
                $table->string('responsible_person', 255)->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('principal_followups');

        Schema::table('inquiries', function (Blueprint $table) {
            $cols = [
                'original_promise_date',
                'latest_followup_date',
                'next_followup_date',
                'principal_production_status',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('inquiries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

