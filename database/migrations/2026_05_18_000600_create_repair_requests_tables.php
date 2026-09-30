<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('repair_requests')) {
            Schema::create('repair_requests', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('repair_code', 50)->unique();
                $table->unsignedBigInteger('damage_report_id')->unique();
                $table->unsignedInteger('technician_user_id')->nullable();
                $table->string('repair_type', 100)->default('corrective');
                $table->text('repair_description');
                $table->decimal('repair_cost', 12, 2)->default(0);
                $table->enum('repair_status', ['pending', 'assigned', 'diagnosing', 'repairing', 'waiting_parts', 'completed', 'failed', 'archived'])->default('pending');
                $table->date('repair_date')->nullable();
                $table->date('estimated_completion_date')->nullable();
                $table->date('completion_date')->nullable();
                $table->text('notes')->nullable();
                $table->text('failure_reason')->nullable();
                $table->unsignedInteger('replacement_item_id')->nullable();
                $table->unsignedInteger('replacement_quantity')->nullable();
                $table->unsignedBigInteger('replacement_dispatch_id')->nullable();
                $table->unsignedInteger('replacement_transaction_id')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->unsignedInteger('updated_by')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index(['repair_status', 'technician_user_id']);
                $table->index(['repair_date', 'estimated_completion_date']);
                $table->foreign('damage_report_id')->references('id')->on('damage_reports')->onDelete('cascade');
                $table->foreign('technician_user_id')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('replacement_item_id')->references('id')->on('items')->nullOnDelete();
                $table->foreign('replacement_dispatch_id')->references('id')->on('dispatches')->nullOnDelete();
                $table->foreign('replacement_transaction_id')->references('id')->on('inventory_transactions')->nullOnDelete();
                $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('repair_histories')) {
            Schema::create('repair_histories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('repair_request_id');
                $table->string('action_type', 50);
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->unsignedInteger('technician_user_id')->nullable();
                $table->text('notes')->nullable();
                $table->longText('meta_json')->nullable();
                $table->unsignedInteger('changed_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['repair_request_id', 'created_at']);
                $table->index(['action_type']);
                $table->foreign('repair_request_id')->references('id')->on('repair_requests')->onDelete('cascade');
                $table->foreign('technician_user_id')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('changed_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('dispatches')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                if (!Schema::hasColumn('dispatches', 'repair_request_id')) {
                    $table->unsignedBigInteger('repair_request_id')->nullable()->after('room_id');
                }
                if (!Schema::hasColumn('dispatches', 'damage_report_id')) {
                    $table->unsignedBigInteger('damage_report_id')->nullable()->after('repair_request_id');
                }
            });

            Schema::table('dispatches', function (Blueprint $table): void {
                if (Schema::hasColumn('dispatches', 'repair_request_id')) {
                    $table->foreign('repair_request_id')->references('id')->on('repair_requests')->nullOnDelete();
                }
                if (Schema::hasColumn('dispatches', 'damage_report_id')) {
                    $table->foreign('damage_report_id')->references('id')->on('damage_reports')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dispatches')) {
            Schema::table('dispatches', function (Blueprint $table): void {
                if (Schema::hasColumn('dispatches', 'damage_report_id')) {
                    $table->dropForeign(['damage_report_id']);
                    $table->dropColumn('damage_report_id');
                }
                if (Schema::hasColumn('dispatches', 'repair_request_id')) {
                    $table->dropForeign(['repair_request_id']);
                    $table->dropColumn('repair_request_id');
                }
            });
        }

        Schema::dropIfExists('repair_histories');
        Schema::dropIfExists('repair_requests');
    }
};
