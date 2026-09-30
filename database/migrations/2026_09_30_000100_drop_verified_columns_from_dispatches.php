<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reverses 2026_09_28_000100_add_verified_columns_to_dispatches.php. The
// Deployment Tracking "Scan QR" action was originally built as an automatic
// verify-on-scan mutation (see that migration's sibling code, now removed
// from DispatchController/DispatchService/deployment-tracking.php). The
// actual intended workflow is a plain QR *lookup* — a sticker on the
// physical item that, when scanned, shows what the item is and where it was
// deployed — with no "Verified/Not Verified" status attached to it. Since
// nothing produces or reads verified_at/verified_by any more, the columns
// come out too rather than sitting unused.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatches', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verified_at', 'verified_by']);
        });
    }

    public function down(): void
    {
        Schema::table('dispatches', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('released_by');
            $table->unsignedInteger('verified_by')->nullable()->after('verified_at');

            $table->foreign('verified_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }
};
