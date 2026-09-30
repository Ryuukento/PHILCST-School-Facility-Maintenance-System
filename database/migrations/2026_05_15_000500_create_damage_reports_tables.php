<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('damage_reports')) {
            Schema::create('damage_reports', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('damage_report_code', 50)->unique();
                $table->unsignedInteger('item_id');
                $table->unsignedInteger('room_id')->nullable();
                $table->unsignedInteger('department_id')->nullable();
                $table->unsignedBigInteger('source_dispatch_id')->nullable();
                $table->text('damage_description');
                $table->enum('severity_level', ['low', 'medium', 'high', 'critical'])->default('medium');
                $table->unsignedInteger('reported_by');
                $table->enum('status', ['pending', 'under_review', 'repairing', 'repaired', 'replaced', 'closed'])->default('pending');
                $table->string('image_path')->nullable();
                $table->text('repair_notes')->nullable();
                $table->unsignedInteger('replacement_item_id')->nullable();
                $table->unsignedInteger('replacement_quantity')->nullable();
                $table->unsignedInteger('replacement_transaction_id')->nullable();
                $table->unsignedInteger('replaced_by')->nullable();
                $table->timestamp('replaced_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'severity_level']);
                $table->index(['item_id', 'room_id']);
                $table->index(['reported_by']);
                $table->index(['department_id']);

                $table->foreign('item_id')->references('id')->on('items')->onDelete('restrict');
                $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
                $table->foreign('department_id')->references('department_id')->on('departments')->nullOnDelete();
                $table->foreign('source_dispatch_id')->references('id')->on('dispatches')->nullOnDelete();
                $table->foreign('reported_by')->references('user_id')->on('users')->onDelete('restrict');
                $table->foreign('replacement_item_id')->references('id')->on('items')->nullOnDelete();
                $table->foreign('replacement_transaction_id')->references('id')->on('inventory_transactions')->nullOnDelete();
                $table->foreign('replaced_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('damage_report_histories')) {
            Schema::create('damage_report_histories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('damage_report_id');
                $table->string('action_type', 50);
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->text('notes')->nullable();
                $table->longText('meta_json')->nullable();
                $table->unsignedInteger('changed_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['damage_report_id', 'created_at']);
                $table->index(['action_type']);

                $table->foreign('damage_report_id')->references('id')->on('damage_reports')->onDelete('cascade');
                $table->foreign('changed_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_report_histories');
        Schema::dropIfExists('damage_reports');
    }
};
