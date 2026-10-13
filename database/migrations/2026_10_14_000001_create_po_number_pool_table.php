<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pool of official PO numbers, generated ahead of time.
     *
     * The supervisor asked for the Official PO Number to be a dropdown rather
     * than typed text. A typed number can clash or be mistyped, and the clash is
     * only caught after the fact; picking from a pool makes that impossible.
     *
     * Numbers are supplier-prefixed (PO-PETRON-2026-001) so a PO can be traced
     * back to its refinery by eye.
     */
    public function up(): void
    {
        if (Schema::hasTable('po_number_pool')) {
            return;
        }

        Schema::create('po_number_pool', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 64)->unique();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            // AVAILABLE numbers can still be picked; USED ones are spent.
            $table->string('status', 16)->default('AVAILABLE');
            $table->timestamp('reserved_at')->nullable();
            $table->foreignId('used_by_purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_number_pool');
    }
};