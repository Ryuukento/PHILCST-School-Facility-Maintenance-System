<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('email', 255);
            $table->string('otp_code', 6);
            $table->integer('otp_attempts')->default(0);
            $table->timestamp('otp_expires_at');
            $table->timestamp('last_otp_sent_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_verified']);
            $table->index(['email', 'is_verified']);
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
    }
};
