<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Receipts — Proof of Receipt photo upload.
 *
 * Suggested by the school's IT reviewer: let whoever records a purchase
 * receipt attach a photo of the physical OR/receipt as documentation. This
 * mirrors `maintenance_reports.completion_proof_image` exactly — a nullable
 * public-URL string, uploaded and stored via App\Http\Controllers\Api\
 * PurchaseReceiptController::uploadProofImage() with raw $file->move() (see
 * that controller for the MIME/size rules), not a Storage-facade path.
 *
 * Deliberately independent of draft/posted status: this is documentation,
 * not a stock-affecting field, so it can be attached or replaced at any time
 * regardless of whether the receipt has been posted yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_receipts')) {
            return;
        }

        Schema::table('purchase_receipts', function (Blueprint $table): void {
            if (!Schema::hasColumn('purchase_receipts', 'proof_image')) {
                $table->string('proof_image')->nullable()->after('remarks');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchase_receipts')) {
            return;
        }

        Schema::table('purchase_receipts', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_receipts', 'proof_image')) {
                $table->dropColumn('proof_image');
            }
        });
    }
};
