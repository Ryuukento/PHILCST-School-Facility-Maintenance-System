<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            if (!Schema::hasColumn('items', 'brand')) {
                $table->string('brand', 100)->nullable()->after('category_id');
            }

            if (!Schema::hasColumn('items', 'model')) {
                $table->string('model', 100)->nullable()->after('brand');
            }

            if (!Schema::hasColumn('items', 'item_condition')) {
                $table->string('item_condition', 50)->nullable()->after('unit_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            $columns = [];
            foreach (['brand', 'model', 'item_condition'] as $column) {
                if (Schema::hasColumn('items', $column)) {
                    $columns[] = $column;
                }
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
