<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            if (!Schema::hasColumn('inquiries', 'portal_submission_required')) {
                $table->string('portal_submission_required', 10)->nullable()->default('Yes')->after('sales_invoice_date');
            }
            if (!Schema::hasColumn('inquiries', 'portal_submission_method')) {
                $table->string('portal_submission_method', 50)->nullable()->default('Vendor Portal')->after('portal_submission_required');
            }
            if (!Schema::hasColumn('inquiries', 'portal_submission_status')) {
                $table->string('portal_submission_status', 50)->nullable()->default('Pending')->after('portal_submission_method');
            }
            if (!Schema::hasColumn('inquiries', 'portal_submission_date')) {
                $table->date('portal_submission_date')->nullable()->after('portal_submission_status');
            }
            if (!Schema::hasColumn('inquiries', 'portal_ack_ref')) {
                $table->string('portal_ack_ref', 255)->nullable()->after('portal_submission_date');
            }
            if (!Schema::hasColumn('inquiries', 'portal_rejection_reason')) {
                $table->text('portal_rejection_reason')->nullable()->after('portal_ack_ref');
            }
            if (!Schema::hasColumn('inquiries', 'portal_resubmission_date')) {
                $table->date('portal_resubmission_date')->nullable()->after('portal_rejection_reason');
            }
            if (!Schema::hasColumn('inquiries', 'portal_resubmission_remarks')) {
                $table->text('portal_resubmission_remarks')->nullable()->after('portal_resubmission_date');
            }
            if (!Schema::hasColumn('inquiries', 'portal_supporting_docs')) {
                $table->string('portal_supporting_docs', 50)->nullable()->default('Pending')->after('portal_resubmission_remarks');
            }
            if (!Schema::hasColumn('inquiries', 'portal_next_followup_date')) {
                $table->date('portal_next_followup_date')->nullable()->after('portal_supporting_docs');
            }
            if (!Schema::hasColumn('inquiries', 'portal_responsible_person')) {
                $table->string('portal_responsible_person', 255)->nullable()->after('portal_next_followup_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn([
                'portal_submission_required',
                'portal_submission_method',
                'portal_submission_status',
                'portal_submission_date',
                'portal_ack_ref',
                'portal_rejection_reason',
                'portal_resubmission_date',
                'portal_resubmission_remarks',
                'portal_supporting_docs',
                'portal_next_followup_date',
                'portal_responsible_person',
            ]);
        });
    }
};
