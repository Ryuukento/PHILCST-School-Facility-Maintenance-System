<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('floors', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('building_id')->references('id')->on('buildings')->onDelete('cascade');
            $table->unique(['building_id', 'name']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('building_id');
            $table->unsignedInteger('floor_id')->nullable();
            $table->string('name');
            $table->integer('capacity')->nullable();
            $table->timestamps();

            $table->foreign('building_id')->references('id')->on('buildings')->onDelete('cascade');
            $table->foreign('floor_id')->references('id')->on('floors')->onDelete('cascade');
            $table->unique(['building_id', 'name']);
            $table->unique(['floor_id', 'name']);
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('room_id');
            $table->string('name');
            $table->enum('status', ['available', 'damaged', 'low_stock', 'out_of_stock', 'maintenance'])->default('available');
            $table->integer('quantity')->default(1);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
    }
};
