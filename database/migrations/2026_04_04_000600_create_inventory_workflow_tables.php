<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items')) {
            Schema::table('items', function (Blueprint $table): void {
                if (!Schema::hasColumn('items', 'reserved_quantity')) {
                    $table->integer('reserved_quantity')->default(0)->after('quantity');
                }

                if (!Schema::hasColumn('items', 'reorder_level')) {
                    $table->integer('reorder_level')->default(5)->after('reserved_quantity');
                }
            });
        }

        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('item_id');
            $table->unsignedInteger('report_id')->nullable();
            $table->unsignedInteger('room_id')->nullable();
            $table->enum('transaction_type', ['reserve', 'release', 'deploy', 'return', 'adjustment', 'dispose']);
            $table->integer('quantity');
            $table->text('reference_note')->nullable();
            $table->unsignedInteger('performed_by')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'transaction_type']);
            $table->index(['report_id']);
            $table->index(['room_id']);
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
            $table->foreign('report_id')->references('report_id')->on('maintenance_reports')->onDelete('set null');
            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('set null');
            $table->foreign('performed_by')->references('user_id')->on('users')->onDelete('set null');
        });

        Schema::create('report_inventory_allocations', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('report_id');
            $table->unsignedInteger('item_id');
            $table->unsignedInteger('room_id');
            $table->integer('reserved_qty')->default(0);
            $table->integer('deployed_qty')->default(0);
            $table->enum('status', ['reserved', 'deployed', 'cancelled'])->default('reserved');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'status']);
            $table->index(['item_id', 'room_id']);
            $table->foreign('report_id')->references('report_id')->on('maintenance_reports')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
            $table->foreign('created_by')->references('user_id')->on('users')->onDelete('set null');
        });

        Schema::create('restock_requests', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('item_name');
            $table->integer('requested_qty');
            $table->text('reason')->nullable();
            $table->unsignedInteger('source_report_id')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['open', 'approved', 'ordered', 'received', 'cancelled'])->default('open');
            $table->unsignedInteger('requested_by')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->foreign('source_report_id')->references('report_id')->on('maintenance_reports')->onDelete('set null');
            $table->foreign('requested_by')->references('user_id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('user_id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_requests');
        Schema::dropIfExists('report_inventory_allocations');
        Schema::dropIfExists('inventory_transactions');

        if (Schema::hasTable('items')) {
            Schema::table('items', function (Blueprint $table): void {
                if (Schema::hasColumn('items', 'reorder_level')) {
                    $table->dropColumn('reorder_level');
                }

                if (Schema::hasColumn('items', 'reserved_quantity')) {
                    $table->dropColumn('reserved_quantity');
                }
            });
        }
    }
};
