<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Guarded: the earlier module-3 version of this change (migration
     * 2026_09_07_000003) may already have added the column under a different
     * migration name, in which case there is nothing left to do.
     */
    public function up(): void
    {
        if (Schema::hasColumn('stock_ins', 'purchase_order_delivery_id')) {
            return;
        }

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->foreignId('purchase_order_delivery_id')->nullable()->after('admin_id')->constrained('purchase_order_deliveries')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_delivery_id');
        });
    }
};
