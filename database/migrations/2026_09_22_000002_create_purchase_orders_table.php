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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 64)->nullable();
            $table->string('po_type', 32)->default('STANDARD_REPLENISHMENT'); // STANDARD_REPLENISHMENT, FUEL_TRADE, BUY_BACK
            $table->string('supplier_name', 128)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('linked_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->integer('qty_ordered');
            $table->string('request_status', 32)->default('REQUESTED'); // REQUESTED, RECEIVED, CONFIRMED, FOR_DELIVERY, COMPLETED
            $table->string('status', 32)->default('Pending'); // Pending, Fulfilled, Cancelled
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('request_date')->useCurrent();
            $table->date('date_needed')->nullable();
            $table->json('requested_products')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revised_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
