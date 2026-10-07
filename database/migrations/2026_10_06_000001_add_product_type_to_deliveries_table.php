<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('product_type', 1)->nullable()->after('order_id'); // U, D, P
        });

        // Existing deliveries on single-product orders inherit that product.
        // (Deliveries on multi-product orders are left blank - they predate product tracking.)
        DB::statement('
            UPDATE deliveries d
            JOIN (
                SELECT order_id, MAX(product_type) AS pt
                FROM order_items
                GROUP BY order_id
                HAVING COUNT(*) = 1
            ) oi ON oi.order_id = d.order_id
            SET d.product_type = oi.pt
        ');
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }
};