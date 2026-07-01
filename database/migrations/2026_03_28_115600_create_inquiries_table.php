<?php

// use Illuminate\Database\Migrations\Migration;
// use Illuminate\Database\Schema\Blueprint;
// use Illuminate\Support\Facades\Schema;

// return new class extends Migration
// {
    /**
     * Run the migrations.
     */
    // public function up(): void
    // {
        // Schema::create('inquiries', function (Blueprint $table) {
        //    $table->id();
            // $table->foreignId('user_id');
            // $table->string('title');
            // $table->text('description');
            // $table->timestamps();
        // });
    // }

    /**
     * Reverse the migrations.
     */
    // public function down(): void
    // {
        // Schema::dropIfExists('inquiries');
    // }
// };




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
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->string('inquiry_id')->nullable();
            $table->string('inquiry_code')->nullable();
            $table->date('inquiry_date')->nullable();

            $table->string('enquiry_type')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();

            $table->string('concerned_person')->nullable();
            $table->string('concerned_area')->nullable();

            $table->unsignedBigInteger('item_id')->nullable();
            $table->text('product_details')->nullable();
            $table->integer('quantity')->nullable();

            $table->unsignedBigInteger('principal_id')->nullable();
            $table->string('status')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
