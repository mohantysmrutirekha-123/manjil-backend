<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Track which opportunity item row was converted, and to which inquiry row
        Schema::table('opportunities', function (Blueprint $table) {
            $table->unsignedBigInteger('converted_inquiry_id')->nullable()->after('item_status');
        });

        // Track, on the inquiry side, which opportunity item it originated from
        Schema::table('inquiries', function (Blueprint $table) {
            $table->unsignedBigInteger('source_opportunity_item_id')->nullable()->after('principal_id');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('converted_inquiry_id');
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('source_opportunity_item_id');
        });
    }
};