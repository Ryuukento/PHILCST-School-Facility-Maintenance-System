<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->unique();
                $table->string('contact_person')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('phone')->nullable();
                $table->text('address')->nullable();
                $table->text('notes')->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('supplier_histories')) {
            Schema::create('supplier_histories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('supplier_id');
                $table->string('action');
                $table->text('details')->nullable();
                $table->unsignedInteger('performed_by')->nullable();
                $table->timestamps();

                $table->index(['supplier_id', 'performed_by']);
                $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
                $table->foreign('performed_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('inventory_stock_entries') && !Schema::hasColumn('inventory_stock_entries', 'supplier_id')) {
            Schema::table('inventory_stock_entries', function (Blueprint $table): void {
                $table->unsignedInteger('supplier_id')->nullable()->after('supplier_name');
                $table->index('supplier_id');
                $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_stock_entries') && Schema::hasColumn('inventory_stock_entries', 'supplier_id')) {
            Schema::table('inventory_stock_entries', function (Blueprint $table): void {
                $table->dropForeign(['supplier_id']);
                $table->dropIndex(['supplier_id']);
                $table->dropColumn('supplier_id');
            });
        }

        Schema::dropIfExists('supplier_histories');
        Schema::dropIfExists('suppliers');
    }
};
