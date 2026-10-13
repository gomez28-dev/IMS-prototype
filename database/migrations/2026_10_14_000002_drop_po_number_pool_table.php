<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Withdraws the pre-generated PO number pool.
     *
     * The pool existed so the Create Supplier Purchase Order page could offer a
     * dropdown of numbers. The supervisor corrected that: the PO number is typed
     * manually on that page, and the dropdown of existing POs belongs only in the
     * ATL and Stock Request forms.
     *
     * Only this table goes. No purchase order is touched, and existing POs keep
     * whatever number they were already issued.
     */
    public function up(): void
    {
        Schema::dropIfExists('po_number_pool');
    }

    public function down(): void
    {
        if (Schema::hasTable('po_number_pool')) {
            return;
        }

        Schema::create('po_number_pool', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 64)->unique();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
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
};
