<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves product type off the order and onto per-order product lines.
     *
     * Every step is guarded so this migration is safe to run on a server where
     * an earlier, partially-applied version of these changes may already exist
     * (columns or tables present, or not) — it never assumes a clean slate and
     * never fails on "duplicate column"/"table already exists".
     */
    public function up(): void
    {
        // 1. Order total (the sum of its product lines) on the order itself.
        if (!Schema::hasColumn('orders', 'amount')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('amount', 15, 2)->default(0)->after('price');
            });
        }

        // 2. One product line per product (Unleaded / Diesel / Premium).
        if (!Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('product_type', 1)->nullable(); // U, D, P (null = not specified on older orders)
                $table->unsignedInteger('qty');
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->timestamps();
            });

            // Convert every existing order into a single product line. The
            // product_type column only exists if an earlier partial migration
            // added it, so fall back to NULL (meaning "not specified") if not.
            $productTypeExpr = Schema::hasColumn('orders', 'product_type')
                ? 'product_type'
                : 'NULL';

            DB::statement(
                'INSERT INTO order_items (order_id, product_type, qty, price, amount, created_at, updated_at)
                 SELECT id, ' . $productTypeExpr . ', qty_ordered, price, ROUND(qty_ordered * price, 2), NOW(), NOW()
                 FROM orders'
            );
        }

        // 3. Make sure every order's stored total matches qty x price.
        DB::statement('UPDATE orders SET amount = ROUND(qty_ordered * price, 2) WHERE amount IS NULL OR amount = 0');

        // 4. Product type now lives on each line, not on the order.
        if (Schema::hasColumn('orders', 'product_type')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('product_type');
            });
        }
    }

    public function down(): void
    {
        // Restore the order-level product type column.
        if (!Schema::hasColumn('orders', 'product_type')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('product_type', 1)->nullable()->after('order_category');
            });
        }

        Schema::dropIfExists('order_items');

        // orders.amount is intentionally left in place: the Order model reads it
        // for totals, so dropping it here would break the app on rollback.
    }
};
