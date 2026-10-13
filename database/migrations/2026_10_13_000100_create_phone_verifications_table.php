<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mirrors email_verifications (2026_10_04_000100_create_email_verifications_table.php)
     * so the Phone Number field in Account Settings can go through the same
     * "send a 6-digit code, verify it before the value is trusted" flow the
     * Email Address field already uses — the user asked for phone numbers to
     * be proven legit (actually reachable by SMS) before being saved to
     * users.phone, instead of being accepted as free text.
     */
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('phone', 30);
            $table->string('otp_code', 6);
            $table->integer('otp_attempts')->default(0);
            $table->timestamp('otp_expires_at');
            $table->timestamp('last_otp_sent_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_verified']);
            $table->index(['phone', 'is_verified']);
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
