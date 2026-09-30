<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        if (!Schema::hasColumn('items', 'item_type')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->enum('item_type', ['room_asset', 'inventory_stock'])
                    ->default('inventory_stock')
                    ->after('room_id');
            });
        }

        $this->makeRoomIdNullableIfNeeded();

        if (!$this->hasIndex('items', 'items_item_type_index')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->index('item_type');
            });
        }

        DB::statement("UPDATE items SET item_type = 'room_asset' WHERE room_id IS NOT NULL");
        DB::statement("UPDATE items SET item_type = 'inventory_stock' WHERE room_id IS NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        if (Schema::hasColumn('items', 'item_type')) {
            $nullRoomItemCount = (int) DB::table('items')->whereNull('room_id')->count();
            if ($nullRoomItemCount > 0) {
                throw new RuntimeException('Cannot revert items.room_id to NOT NULL while inventory stock rows still have no room_id.');
            }

            if ($this->hasIndex('items', 'items_item_type_index')) {
                Schema::table('items', function (Blueprint $table): void {
                    $table->dropIndex(['item_type']);
                });
            }

            Schema::table('items', function (Blueprint $table): void {
                $table->dropColumn('item_type');
            });
        }

        $this->makeRoomIdNotNullableIfNeeded();
    }

    private function makeRoomIdNullableIfNeeded(): void
    {
        $column = $this->getColumnMetadata('items', 'room_id');
        if (!$column || strtoupper((string) ($column->Null ?? 'NO')) === 'YES') {
            return;
        }

        DB::statement('ALTER TABLE items MODIFY room_id INT UNSIGNED NULL');
    }

    private function makeRoomIdNotNullableIfNeeded(): void
    {
        $column = $this->getColumnMetadata('items', 'room_id');
        if (!$column || strtoupper((string) ($column->Null ?? 'NO')) === 'NO') {
            return;
        }

        DB::statement('ALTER TABLE items MODIFY room_id INT UNSIGNED NOT NULL');
    }

    private function getColumnMetadata(string $table, string $column): ?object
    {
        $rows = DB::select(
            "SELECT COLUMN_NAME AS `Field`, COLUMN_TYPE AS `Type`, IS_NULLABLE AS `Null`, COLUMN_KEY AS `Key`, COLUMN_DEFAULT AS `Default`, EXTRA AS `Extra`
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $column]
        );
        return $rows[0] ?? null;
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return !empty($rows);
    }
};
