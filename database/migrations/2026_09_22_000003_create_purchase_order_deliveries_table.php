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
        Schema::create('purchase_order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('delivery_channel', 40)->default('SUPPLIER_DOYEN_PICKUP'); 
            // SUPPLIER_CLIENT_PICKUP, SUPPLIER_STOCKS_DELIVERY, SUPPLIER_DOYEN_PICKUP, BUY_BACK_CLIENT_PICKUP, BUY_BACK_DOYEN_PICKUP, BUY_BACK_STOCKS_DELIVERY
            $table->string('order_type', 32)->default('PICK_UP'); // PICK_UP, DELIVERY
            $table->string('atl_type', 32)->default('DITC_ATL'); // DITC_ATL, CLIENT_ATL, NONE
            $table->string('dr_number', 64)->nullable();
            $table->string('atl_number', 64)->nullable();
            $table->string('client_atl_number', 64)->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->string('so_number', 64)->nullable();
            $table->string('product', 64);
            $table->integer('qty_to_receive');
            $table->date('receiving_date')->nullable();
            $table->string('driver_name', 128)->nullable();
            $table->string('plate_number', 64)->nullable();
            $table->string('location', 128)->nullable();
            $table->text('additional_remarks')->nullable();
            $table->string('status', 32)->default('Pending'); // Pending, Active, Completed, Cancelled
            $table->foreignId('prepared_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revised_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_deliveries');
    }
};
