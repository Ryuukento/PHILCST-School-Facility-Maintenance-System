<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_reports', function (Blueprint $table): void {
            $table->increments('report_id');
            $table->string('title');
            $table->longText('description');
            $table->string('location')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent', 'critical'])->default('medium');
            $table->enum('status', ['submitted', 'in_progress', 'completed', 'closed', 'cancelled'])->default('submitted');
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('assigned_to')->nullable();
            $table->unsignedInteger('department_id')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['created_by', 'assigned_to']);
            $table->foreign('created_by')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('assigned_to')->references('user_id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_reports');
    }
};
