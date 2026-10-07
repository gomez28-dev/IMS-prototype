<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('product_type', 1)->nullable(); // U, D, P
            $table->unsignedInteger('qty_out');
            $table->unsignedTinyInteger('compartment_no')->nullable();
            $table->timestamps();

            $table->index(['delivery_id', 'product_type']);
        });

        DB::statement('
            INSERT INTO delivery_items (delivery_id, product_type, qty_out, compartment_no, created_at, updated_at)
            SELECT id, product_type, qty_out, 1, NOW(), NOW()
            FROM deliveries
            WHERE qty_out > 0
        ');

        Schema::table('delivery_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_item_id')->nullable()->after('delivery_id');
            $table->index('delivery_item_id');
        });

        DB::statement('
            UPDATE delivery_allocations da
            JOIN delivery_items di ON di.delivery_id = da.delivery_id
            SET da.delivery_item_id = di.id
        ');
    }

    public function down(): void
    {
        Schema::table('delivery_allocations', function (Blueprint $table) {
            $table->dropIndex(['delivery_item_id']);
            $table->dropColumn('delivery_item_id');
        });
        Schema::dropIfExists('delivery_items');
    }
};