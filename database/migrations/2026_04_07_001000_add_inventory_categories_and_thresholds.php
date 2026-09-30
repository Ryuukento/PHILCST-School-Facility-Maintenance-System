<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_categories')) {
            Schema::create('inventory_categories', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->unique();
                $table->string('code')->nullable()->unique();
                $table->integer('default_low_stock_threshold')->nullable();
                $table->boolean('allow_threshold_override')->default(true);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('items')) {
            Schema::table('items', function (Blueprint $table): void {
                if (!Schema::hasColumn('items', 'category_id')) {
                    $table->unsignedInteger('category_id')->nullable()->after('room_id');
                }

                if (!Schema::hasColumn('items', 'low_stock_threshold_override')) {
                    $table->integer('low_stock_threshold_override')->nullable()->after('quantity');
                }
            });

            Schema::table('items', function (Blueprint $table): void {
                $hasCategoryForeign = collect(DB::select("\n                    SELECT CONSTRAINT_NAME\n                    FROM information_schema.KEY_COLUMN_USAGE\n                    WHERE TABLE_SCHEMA = DATABASE()\n                      AND TABLE_NAME = 'items'\n                      AND COLUMN_NAME = 'category_id'\n                      AND REFERENCED_TABLE_NAME = 'inventory_categories'\n                "))->isNotEmpty();

                if (Schema::hasColumn('items', 'category_id') && !$hasCategoryForeign) {
                    $table->foreign('category_id')->references('id')->on('inventory_categories')->nullOnDelete();
                }

                $hasCategoryIndex = collect(DB::select("\n                    SELECT INDEX_NAME\n                    FROM information_schema.STATISTICS\n                    WHERE TABLE_SCHEMA = DATABASE()\n                      AND TABLE_NAME = 'items'\n                      AND INDEX_NAME = 'items_category_id_index'\n                "))->isNotEmpty();

                if (Schema::hasColumn('items', 'category_id') && !$hasCategoryIndex) {
                    $table->index('category_id');
                }
            });
        }

        if (Schema::hasTable('inventory_categories')) {
            $existing = DB::table('inventory_categories')->pluck('name')->map(fn ($name) => strtolower((string)$name))->toArray();

            $seedRows = [
                [
                    'name' => 'Consumables',
                    'code' => 'consumables',
                    'default_low_stock_threshold' => 20,
                    'allow_threshold_override' => 1,
                    'is_active' => 1,
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Equipment',
                    'code' => 'equipment',
                    'default_low_stock_threshold' => 3,
                    'allow_threshold_override' => 1,
                    'is_active' => 1,
                    'sort_order' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Fixtures',
                    'code' => 'fixtures',
                    'default_low_stock_threshold' => 1,
                    'allow_threshold_override' => 1,
                    'is_active' => 1,
                    'sort_order' => 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            foreach ($seedRows as $row) {
                if (!in_array(strtolower($row['name']), $existing, true)) {
                    DB::table('inventory_categories')->insert($row);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('items')) {
            Schema::table('items', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['category_id']);
                } catch (Throwable $e) {
                    // Ignore when constraint does not exist.
                }

                if (Schema::hasColumn('items', 'category_id')) {
                    $table->dropColumn('category_id');
                }

                if (Schema::hasColumn('items', 'low_stock_threshold_override')) {
                    $table->dropColumn('low_stock_threshold_override');
                }
            });
        }

        Schema::dropIfExists('inventory_categories');
    }
};
