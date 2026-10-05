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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_type', 30)->default('DELIVERY')->after('status'); // DELIVERY, DEPOT_PICKUP, FUEL_TRADE
            $table->string('order_category', 30)->default('CLIENT_ORDER')->after('fulfillment_type'); // CLIENT_ORDER, BUY_BACK
            $table->string('client_atl_number', 64)->nullable()->after('order_category');
            $table->unsignedBigInteger('linked_purchase_order_id')->nullable()->after('client_atl_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'fulfillment_type',
                'order_category',
                'client_atl_number',
                'linked_purchase_order_id',
            ]);
        });
    }
};
