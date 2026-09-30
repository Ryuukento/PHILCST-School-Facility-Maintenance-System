<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dispatches')) {
            Schema::create('dispatches', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('dispatch_code', 50)->unique();
                $table->unsignedInteger('department_id')->nullable();
                $table->unsignedInteger('approved_by')->nullable();
                $table->unsignedInteger('released_by')->nullable();
                $table->unsignedInteger('receiver_user_id')->nullable();
                $table->unsignedInteger('room_id')->nullable();
                $table->enum('status', ['pending','approved','released','cancelled'])->default('pending');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('department_id')->references('department_id')->on('departments')->nullOnDelete();
                $table->foreign('approved_by')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('released_by')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('receiver_user_id')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('dispatch_items')) {
            Schema::create('dispatch_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('dispatch_id');
                $table->unsignedInteger('item_id');
                $table->integer('quantity');
                $table->timestamps();

                $table->foreign('dispatch_id')->references('id')->on('dispatches')->onDelete('cascade');
                $table->foreign('item_id')->references('id')->on('items')->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_items');
        Schema::dropIfExists('dispatches');
    }
};
