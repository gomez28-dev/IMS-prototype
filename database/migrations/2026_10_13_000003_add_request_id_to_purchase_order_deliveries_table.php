<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets an ATL point at the stock request it serves.
     *
     * Doyen Stocks ATLs are issued against a request rather than a sales
     * order, so PurchaseOrderDelivery::order() is null for them today and the
     * only way back to the request is through the purchase_orders row. This
     * column makes that link direct.
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_order_deliveries')) {
            return;
        }

        if (!Schema::hasColumn('purchase_order_deliveries', 'request_id')) {
            Schema::table('purchase_order_deliveries', function (Blueprint $table) {
                $table->foreignId('request_id')
                    ->nullable()
                    ->after('order_id')
                    ->constrained('stock_requests')
                    ->nullOnDelete();

                $table->index('request_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchase_order_deliveries')) {
            return;
        }

        if (Schema::hasColumn('purchase_order_deliveries', 'request_id')) {
            Schema::table('purchase_order_deliveries', function (Blueprint $table) {
                $table->dropForeign(['request_id']);
                $table->dropIndex(['request_id']);
                $table->dropColumn('request_id');
            });
        }
    }
};