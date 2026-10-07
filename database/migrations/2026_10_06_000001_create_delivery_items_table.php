<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded so this is safe on a database where the supervisor already
        // applied the change by hand. Laravel tracks migrations by filename,
        // so an already-recorded migration is skipped regardless of this body.
        if (!Schema::hasTable('delivery_items')) {
            Schema::create('delivery_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
                $table->string('product_type', 1)->nullable(); // U, D, P
                $table->unsignedInteger('qty_out');
                $table->unsignedTinyInteger('compartment_no')->nullable();
                $table->timestamps();

                $table->index(['delivery_id', 'product_type']);
            });

            // One compartment line per existing delivery. NOT EXISTS keeps this
            // idempotent so a re-run cannot duplicate rows.
            DB::statement('
                INSERT INTO delivery_items (delivery_id, product_type, qty_out, compartment_no, created_at, updated_at)
                SELECT d.id, d.product_type, d.qty_out, 1, NOW(), NOW()
                FROM deliveries d
                WHERE d.qty_out > 0
                  AND NOT EXISTS (SELECT 1 FROM delivery_items di WHERE di.delivery_id = d.id)
            ');
        }

        if (!Schema::hasColumn('delivery_allocations', 'delivery_item_id')) {
            Schema::table('delivery_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('delivery_item_id')->nullable()->after('delivery_id');
                $table->index('delivery_item_id');
            });
        }

        // Attach pre-existing allocations to their delivery's compartment.
        // Restricted to unlinked rows so a re-run is a no-op.
        DB::statement('
            UPDATE delivery_allocations da
            JOIN delivery_items di ON di.delivery_id = da.delivery_id
            SET da.delivery_item_id = di.id
            WHERE da.delivery_item_id IS NULL
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