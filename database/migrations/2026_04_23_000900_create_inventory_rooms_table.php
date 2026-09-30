<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_rooms')) {
            Schema::create('inventory_rooms', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->unique();
                $table->string('code')->nullable()->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('items') && !Schema::hasColumn('items', 'inventory_room_id')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->unsignedInteger('inventory_room_id')->nullable()->after('room_id');
                $table->index('inventory_room_id');
                $table->foreign('inventory_room_id')->references('id')->on('inventory_rooms')->nullOnDelete();
            });
        }

        $this->seedDefaultInventoryRooms();
    }

    public function down(): void
    {
        if (Schema::hasTable('items') && Schema::hasColumn('items', 'inventory_room_id')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropForeign(['inventory_room_id']);
                $table->dropIndex(['inventory_room_id']);
                $table->dropColumn('inventory_room_id');
            });
        }

        Schema::dropIfExists('inventory_rooms');
    }

    private function seedDefaultInventoryRooms(): void
    {
        if (!Schema::hasTable('inventory_rooms')) {
            return;
        }

        $defaults = [
            ['name' => 'Maritime Inventory Room', 'code' => 'maritime', 'description' => 'Stockroom for maritime equipment and replacement items.', 'sort_order' => 10],
            ['name' => 'Electrical Inventory Room', 'code' => 'electrical', 'description' => 'Stockroom for electrical tools, parts, and replacement items.', 'sort_order' => 20],
        ];

        foreach ($defaults as $row) {
            $exists = DB::table('inventory_rooms')->whereRaw('LOWER(name) = ?', [strtolower($row['name'])])->exists();
            if ($exists) {
                continue;
            }

            DB::table('inventory_rooms')->insert([
                'name' => $row['name'],
                'code' => $row['code'],
                'description' => $row['description'],
                'is_active' => 1,
                'sort_order' => $row['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
