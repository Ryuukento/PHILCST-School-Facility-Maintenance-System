<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deployed Asset registry — per-unit asset identity for dispatched items.
     *
     * An `inventory_stock` items row stands for many physical units, so its
     * single UNIQUE items.asset_code cannot identify one unit. Units released
     * through a Dispatch with "Track as Assets" enabled each get their own
     * deployed_assets row instead (see App\Services\AssetRegistryService).
     *
     * PURELY ADDITIVE:
     *   - items, dispatches, dispatch_items and inventory_transactions are not
     *     altered, so stock quantities and every existing total are unchanged.
     *   - deployed_assets is NOT an items row, so the queries that union
     *     released dispatch lines with `room_asset` items (Buildings Overview,
     *     Deployment Tracking, Analytics, Rooms) cannot double-count a unit.
     *   - damage_reports.deployed_asset_id / maintenance_reports.deployed_asset_id
     *     are nullable; existing reports keep NULL and are not backfilled.
     *   - Already-released dispatches are not backfilled with codes.
     *
     * Every identifier below is explicitly named and kept under MySQL's
     * 64-character limit.
     */
    public function up(): void
    {
        if (!Schema::hasTable('asset_code_sequences')) {
            // One counter row per year, locked FOR UPDATE while a code is
            // generated, so two concurrent releases can never be handed the
            // same SFMS-YYYY-NNNNNN code.
            Schema::create('asset_code_sequences', function (Blueprint $table): void {
                $table->unsignedSmallInteger('year')->primary();
                $table->unsignedInteger('last_value')->default(0);
                $table->timestamps();
            });
        }

        // Pre-create this year's counter so the first releases of the year
        // only ever lock an existing row (a SELECT ... FOR UPDATE on a missing
        // row takes gap locks, which two first-time callers could deadlock
        // on). AssetRegistryService still creates future years on demand.
        DB::table('asset_code_sequences')->insertOrIgnore([
            'year' => (int) now()->format('Y'),
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!Schema::hasTable('deployed_assets')) {
            Schema::create('deployed_assets', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('asset_code', 50);
                $table->enum('code_source', ['existing', 'generated']);
                $table->unsignedInteger('item_id');
                $table->unsignedBigInteger('dispatch_id')->nullable();
                $table->unsignedBigInteger('dispatch_item_id')->nullable();
                $table->unsignedInteger('room_id')->nullable();
                $table->enum('status', ['active', 'returned', 'disposed'])->default('active');
                $table->timestamp('deployed_at')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique('asset_code', 'deployed_assets_asset_code_unique');
                $table->index(['room_id', 'status'], 'deployed_assets_room_status_index');
                $table->index('item_id', 'deployed_assets_item_id_index');
                $table->index('dispatch_id', 'deployed_assets_dispatch_id_index');

                // restrict matches dispatch_items.item_id: an item that has
                // been dispatched can already not be deleted.
                $table->foreign('item_id', 'deployed_assets_item_id_foreign')
                    ->references('id')->on('items')->restrictOnDelete();
                $table->foreign('dispatch_id', 'deployed_assets_dispatch_id_foreign')
                    ->references('id')->on('dispatches')->nullOnDelete();
                $table->foreign('dispatch_item_id', 'deployed_assets_dispatch_item_id_foreign')
                    ->references('id')->on('dispatch_items')->nullOnDelete();
                $table->foreign('room_id', 'deployed_assets_room_id_foreign')
                    ->references('id')->on('rooms')->nullOnDelete();
                $table->foreign('created_by', 'deployed_assets_created_by_foreign')
                    ->references('user_id')->on('users')->nullOnDelete();
            });
        }

        foreach (['damage_reports', 'maintenance_reports'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'deployed_asset_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->unsignedBigInteger('deployed_asset_id')->nullable();
                $table->index('deployed_asset_id', $tableName . '_deployed_asset_id_index');
                $table->foreign('deployed_asset_id', $tableName . '_deployed_asset_id_foreign')
                    ->references('id')->on('deployed_assets')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['damage_reports', 'maintenance_reports'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'deployed_asset_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign($tableName . '_deployed_asset_id_foreign');
                $table->dropIndex($tableName . '_deployed_asset_id_index');
                $table->dropColumn('deployed_asset_id');
            });
        }

        Schema::dropIfExists('deployed_assets');
        Schema::dropIfExists('asset_code_sequences');
    }
};
