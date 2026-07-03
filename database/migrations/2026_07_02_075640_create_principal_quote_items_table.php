<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('principal_quote_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('principal_quote_id');
            $table->unsignedBigInteger('item_id');
            $table->integer('qty');
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->foreign('principal_quote_id')
                  ->references('id')
                  ->on('principal_quotes')
                  ->onDelete('cascade');


        $table->foreign('item_id')
                ->references('id')
                ->on('crm_items')
                ->onDelete('cascade');
                
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('principal_quote_items');
    }
};