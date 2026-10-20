<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('product_type', 1)->nullable(); // U, D, P (null = not specified on older orders)
            $table->unsignedInteger('qty');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();
        });

        // Convert every existing order into a single product line.
        DB::statement('
            INSERT INTO order_items (order_id, product_type, qty, price, amount, created_at, updated_at)
            SELECT id, product_type, qty_ordered, price, ROUND(qty_ordered * price, 2), NOW(), NOW()
            FROM orders
        ');

        // Product type now lives on each line, not on the order.
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('product_type', 1)->nullable()->after('order_category');
        });

        Schema::dropIfExists('order_items');
    }
};