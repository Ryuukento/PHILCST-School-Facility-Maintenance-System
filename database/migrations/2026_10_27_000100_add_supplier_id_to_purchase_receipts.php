<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links purchase_receipts to the suppliers table (previously only a free-text
 * supplier_name string, so there was no way to query "all receipts/items ever
 * bought from supplier X" -- a prerequisite for the Reorder Previous Receipt
 * and Frequently Purchased Items features requested 2026-10-27).
 *
 * Mirrors the exact pattern already used for inventory_stock_entries.supplier_id
 * in 2026_05_15_000300_create_suppliers_and_histories_and_add_supplier_id.php:
 * nullable, nullOnDelete, added ALONGSIDE the existing supplier_name column
 * rather than replacing it.
 *
 * Deliberately additive/non-destructive:
 *  - supplier_id is nullable, so every existing receipt row (which only ever
 *    had a free-text name, never a real supplier link) stays valid with no
 *    backfill and no risk of a failed/ambiguous name-matching migration step.
 *  - supplier_name is left untouched and keeps being the column every existing
 *    read path (index/show/search in PurchaseReceiptController) already
 *    selects, so none of those queries need to change for this migration to
 *    be safe to run.
 *  - nullOnDelete: deleting a supplier from Supplier Management must not be
 *    blocked by, or cascade-delete, historical purchase receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_receipts') && !Schema::hasColumn('purchase_receipts', 'supplier_id')) {
            Schema::table('purchase_receipts', function (Blueprint $table): void {
                $table->unsignedInteger('supplier_id')->nullable()->after('supplier_name');
                $table->index('supplier_id');
                $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_receipts') && Schema::hasColumn('purchase_receipts', 'supplier_id')) {
            Schema::table('purchase_receipts', function (Blueprint $table): void {
                $table->dropForeign(['supplier_id']);
                $table->dropIndex(['supplier_id']);
                $table->dropColumn('supplier_id');
            });
        }
    }
};
