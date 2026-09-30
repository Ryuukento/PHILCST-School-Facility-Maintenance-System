<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add purchase_receipt_id to dispatches so a dispatch can be explicitly
     * linked to the OR / purchase receipt the stock came from.
     * Nullable — existing dispatches default to NULL (no linked receipt).
     */
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table): void {
            if (!Schema::hasColumn('dispatches', 'purchase_receipt_id')) {
                $table->unsignedBigInteger('purchase_receipt_id')
                      ->nullable()
                      ->after('notes')
                      ->comment('Optional link to the purchase receipt this dispatch sources from');

                $table->foreign('purchase_receipt_id')
                      ->references('id')
                      ->on('purchase_receipts')
                      ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table): void {
            if (Schema::hasColumn('dispatches', 'purchase_receipt_id')) {
                $table->dropForeign(['purchase_receipt_id']);
                $table->dropColumn('purchase_receipt_id');
            }
        });
    }
};
