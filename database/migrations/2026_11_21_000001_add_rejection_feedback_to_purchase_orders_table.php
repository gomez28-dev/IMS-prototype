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
            if (! Schema::hasColumn('purchase_orders', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('remarks');
            }

            if (! Schema::hasColumn('purchase_orders', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('remarks')->constrained('admins')->nullOnDelete();
            }

            if (! Schema::hasColumn('purchase_orders', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('remarks');
            }

            if (! Schema::hasColumn('purchase_orders', 'rejection_seen_at')) {
                $table->timestamp('rejection_seen_at')->nullable()->after('remarks');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders', 'rejected_by')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropForeign(['rejected_by']);
            });
        }

        foreach (['rejection_seen_at', 'rejected_at', 'rejected_by', 'rejection_reason'] as $column) {
            if (Schema::hasColumn('purchase_orders', $column)) {
                Schema::table('purchase_orders', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
