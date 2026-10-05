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
        Schema::table('purchase_order_deliveries', function (Blueprint $table) {
            $table->foreignId('storage_tank_id')->nullable()->after('purchase_order_id')->constrained('storage_tanks')->nullOnDelete();
            $table->string('supplier_so_number', 64)->nullable()->after('client_atl_number');
            $table->string('supplier_dr_number', 64)->nullable()->after('supplier_so_number');
            $table->text('scanned_doc_url')->nullable()->after('supplier_dr_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_deliveries', function (Blueprint $table) {
            $table->dropForeign(['storage_tank_id']);
            $table->dropColumn([
                'storage_tank_id',
                'supplier_so_number',
                'supplier_dr_number',
                'scanned_doc_url',
            ]);
        });
    }
};
