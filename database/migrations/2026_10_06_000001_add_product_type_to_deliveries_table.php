<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded so this is safe to run against a database where the column
        // was already added manually. Laravel tracks migrations by filename,
        // so editing the body never re-runs an already-recorded migration.
        if (!Schema::hasColumn('deliveries', 'product_type')) {
            Schema::table('deliveries', function (Blueprint $table) {
                $table->string('product_type', 1)->nullable()->after('order_id'); // U, D, P
            });
        }

        // Existing deliveries on single-product orders inherit that product.
        // (Deliveries on multi-product orders are left blank - they predate product tracking.)
        // Restricted to still-null rows so a re-run is a no-op instead of a full table rewrite.
        // Skipped entirely if order_items is absent, so a partially-migrated database
        // still ends up with the column rather than failing outright.
        if (Schema::hasTable('order_items')) {
            DB::statement('
                UPDATE deliveries d
                JOIN (
                    SELECT order_id, MAX(product_type) AS pt
                    FROM order_items
                    GROUP BY order_id
                    HAVING COUNT(*) = 1
                ) oi ON oi.order_id = d.order_id
                SET d.product_type = oi.pt
                WHERE d.product_type IS NULL
            ');
        }
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }
};