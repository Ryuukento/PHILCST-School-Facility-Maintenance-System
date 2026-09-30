<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_receipts')) {
            Schema::create('purchase_receipts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('or_number')->unique();
                $table->date('receipt_date');
                $table->string('supplier_name');
                $table->unsignedInteger('department_id')->nullable();
                $table->unsignedInteger('received_by');
                $table->text('remarks')->nullable();
                $table->enum('status', ['draft', 'posted'])->default('draft');
                $table->timestamps();

                $table->foreign('department_id')->references('department_id')->on('departments')->nullOnDelete();
                $table->foreign('received_by')->references('user_id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipts');
    }
};
