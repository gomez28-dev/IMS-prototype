<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feedback the VP leaves when a purchase order is sent back. The requester
     * reads it on the PO page; rejection_seen_at is what clears their bell.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('remarks');
            $table->foreignId('rejected_by')->nullable()->after('rejection_reason')->constrained('admins')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->timestamp('rejection_seen_at')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejection_seen_at', 'rejected_at', 'rejected_by', 'rejection_reason']);
        });
    }
};