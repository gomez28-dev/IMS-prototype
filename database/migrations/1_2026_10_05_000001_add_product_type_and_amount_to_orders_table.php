<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('product_type', 1)->nullable()->after('order_category'); // U, D, P
            $table->decimal('amount', 15, 2)->default(0)->after('price');
        });

        // Backfill amount for existing orders
        DB::statement('UPDATE orders SET amount = ROUND(qty_ordered * price, 2)');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['product_type', 'amount']);
        });
    }
};