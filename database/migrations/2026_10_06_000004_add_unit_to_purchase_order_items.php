<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-line unit of measure, shown on the printed Purchase Order.
     *
     * Guarded, so it is safe on a server where this column already exists.
     */
    public function up(): void
    {
        if (Schema::hasTable('purchase_order_items') && !Schema::hasColumn('purchase_order_items', 'unit')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->string('unit', 16)->default('LTRS')->after('product');
            });

            // Existing lines are fuel volumes, measured in liters.
            DB::table('purchase_order_items')->whereNull('unit')->update(['unit' => 'LTRS']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_order_items') && Schema::hasColumn('purchase_order_items', 'unit')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }
    }
};
