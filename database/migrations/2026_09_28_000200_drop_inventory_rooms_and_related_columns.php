<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory is a single centralized stock pool — this school does not
     * use separate inventory rooms. Removes the inventory_rooms concept
     * entirely: FK columns on items/purchase_receipt_items/
     * inventory_stock_entries, and finally the inventory_rooms table.
     *
     * Whether inventory_stock_entries.inventory_room_id actually carries a
     * live FK depends on how the schema got here: on the long-lived
     * production DB it had drifted away (confirmed absent via
     * information_schema at the time this migration was first written), but
     * a schema built by replaying every migration from empty — e.g. the
     * legacy-migration-rehearsal scratch database, or `migrate:fresh` —
     * still has it, because 2026_05_15_000100_create_inventory_stock_entries_table.php
     * creates it with restrictOnDelete(). Assuming either way broke the
     * other environment, so this checks information_schema at runtime
     * instead of hard-coding an assumption.
     */
    public function up(): void
    {
        if (Schema::hasColumn('items', 'inventory_room_id')) {
            Schema::table('items', function (Blueprint $table) {
                $this->dropForeignKeyIfExists('items', 'inventory_room_id');
                $table->dropColumn('inventory_room_id');
            });
        }

        if (Schema::hasColumn('purchase_receipt_items', 'inventory_room_id')) {
            Schema::table('purchase_receipt_items', function (Blueprint $table) {
                $this->dropForeignKeyIfExists('purchase_receipt_items', 'inventory_room_id');
                $table->dropColumn('inventory_room_id');
            });
        }

        if (Schema::hasColumn('inventory_stock_entries', 'inventory_room_id')) {
            $this->dropForeignKeyIfExists('inventory_stock_entries', 'inventory_room_id');
            Schema::table('inventory_stock_entries', function (Blueprint $table) {
                $table->dropColumn('inventory_room_id');
            });
        }

        Schema::dropIfExists('inventory_rooms');
    }

    /**
     * Drops the named FK on $table/$column iff one actually exists right
     * now on this connection. Laravel's dropForeign() throws if the named
     * constraint isn't there, and the naming convention alone isn't
     * reliable evidence it is — see the class doc-comment above.
     */
    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite has no ALTER-time named FK constraints to drop; the
            // dropColumn() Blueprint call afterwards is a full table rebuild
            // that already omits the FK.
            return;
        }

        $constraintName = $table . '_' . $column . '_foreign';

        $exists = $connection->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->exists();

        if ($exists) {
            Schema::table($table, function (Blueprint $blueprint) use ($constraintName) {
                $blueprint->dropForeign($constraintName);
            });
        }
    }

    public function down(): void
    {
        Schema::create('inventory_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        if (!Schema::hasColumn('items', 'inventory_room_id')) {
            Schema::table('items', function (Blueprint $table) {
                $table->foreignId('inventory_room_id')->nullable()->constrained('inventory_rooms');
            });
        }

        if (!Schema::hasColumn('purchase_receipt_items', 'inventory_room_id')) {
            Schema::table('purchase_receipt_items', function (Blueprint $table) {
                $table->foreignId('inventory_room_id')->nullable()->constrained('inventory_rooms');
            });
        }

        if (!Schema::hasColumn('inventory_stock_entries', 'inventory_room_id')) {
            Schema::table('inventory_stock_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('inventory_room_id')->nullable();
            });
        }
    }
};
