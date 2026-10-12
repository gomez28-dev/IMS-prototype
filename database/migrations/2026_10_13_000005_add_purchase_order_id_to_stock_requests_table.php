<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a stock request to the purchase order Purchasing raised from it.
     *
     * legacy_purchase_order_id records where a request was copied *from*. This
     * is the other direction: the PO that came out of it, which is also what
     * stops the same request being prepared twice.
     */
    public function up(): void
    {
        if (!Schema::hasTable('stock_requests')) {
            return;
        }

        if (!Schema::hasColumn('stock_requests', 'purchase_order_id')) {
            Schema::table('stock_requests', function (Blueprint $table) {
                $table->foreignId('purchase_order_id')
                    ->nullable()
                    ->after('legacy_purchase_order_id')
                    ->constrained('purchase_orders')
                    ->nullOnDelete();

                $table->index('purchase_order_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('stock_requests')) {
            return;
        }

        if (Schema::hasColumn('stock_requests', 'purchase_order_id')) {
            Schema::table('stock_requests', function (Blueprint $table) {
                $table->dropForeign(['purchase_order_id']);
                $table->dropIndex(['purchase_order_id']);
                $table->dropColumn('purchase_order_id');
            });
        }
    }
};