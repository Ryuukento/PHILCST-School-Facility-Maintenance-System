<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_receipt_items')) {
            Schema::create('purchase_receipt_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('purchase_receipt_id');
                $table->unsignedInteger('item_id')->nullable();
                $table->string('item_name');
                $table->unsignedInteger('category_id')->nullable();
                $table->unsignedInteger('inventory_room_id');
                $table->integer('quantity_received');
                $table->string('unit', 20)->default('pc');
                $table->timestamps();

                $table->foreign('purchase_receipt_id')->references('id')->on('purchase_receipts')->onDelete('cascade');
                $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
                $table->foreign('category_id')->references('id')->on('inventory_categories')->nullOnDelete();
                $table->foreign('inventory_room_id')->references('id')->on('inventory_rooms')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
    }
};
