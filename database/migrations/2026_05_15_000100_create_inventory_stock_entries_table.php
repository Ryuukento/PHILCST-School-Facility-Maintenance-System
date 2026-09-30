<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items') && !Schema::hasColumn('items', 'unit_type')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->string('unit_type', 50)->nullable()->after('name');
            });
        }

        if (!Schema::hasTable('inventory_stock_entries')) {
            Schema::create('inventory_stock_entries', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('stock_entry_id', 40)->unique();
                $table->string('or_number', 100);
                $table->string('supplier_name', 255);
                $table->date('date_received');
                $table->unsignedInteger('item_id')->nullable();
                $table->unsignedInteger('inventory_room_id');
                $table->unsignedInteger('category_id')->nullable();
                $table->unsignedInteger('department_id');
                $table->unsignedInteger('room_id')->nullable();
                $table->unsignedInteger('receiver_user_id');
                $table->string('item_name', 255);
                $table->unsignedInteger('quantity');
                $table->string('unit_type', 50);
                $table->text('description')->nullable();
                $table->string('item_condition', 50);
                $table->timestamps();

                $table->index(['date_received', 'category_id']);
                $table->index(['department_id', 'room_id']);
                $table->index(['receiver_user_id', 'created_at']);

                $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
                $table->foreign('inventory_room_id')->references('id')->on('inventory_rooms')->restrictOnDelete();
                $table->foreign('category_id')->references('id')->on('inventory_categories')->nullOnDelete();
                $table->foreign('department_id')->references('department_id')->on('departments')->restrictOnDelete();
                $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
                $table->foreign('receiver_user_id')->references('user_id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_entries');

        if (Schema::hasTable('items') && Schema::hasColumn('items', 'unit_type')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropColumn('unit_type');
            });
        }
    }
};
