<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('delivery_allocations', 'tank_compartment_id')) {
            Schema::table('delivery_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('tank_compartment_id')->nullable()->after('storage_tank_id');
                $table->index('tank_compartment_id');
            });
        }

        if (!Schema::hasColumn('tank_compartments', 'opening_balance')) {
            Schema::table('tank_compartments', function (Blueprint $table) {
                $table->unsignedInteger('opening_balance')->default(0)->after('product_type');
            });
        }

        // Used by the transfers update (part 2b)
        if (!Schema::hasColumn('stock_transfers', 'source_compartment_id')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->unsignedBigInteger('source_compartment_id')->nullable()->after('destination_tank_id');
                $table->unsignedBigInteger('destination_compartment_id')->nullable()->after('source_compartment_id');
                $table->string('product_type', 1)->nullable()->after('type');
                $table->index('source_compartment_id');
                $table->index('destination_compartment_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_transfers', 'source_compartment_id')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->dropIndex(['source_compartment_id']);
                $table->dropIndex(['destination_compartment_id']);
                $table->dropColumn(['source_compartment_id', 'destination_compartment_id', 'product_type']);
            });
        }

        if (Schema::hasColumn('tank_compartments', 'opening_balance')) {
            Schema::table('tank_compartments', function (Blueprint $table) {
                $table->dropColumn('opening_balance');
            });
        }

        if (Schema::hasColumn('delivery_allocations', 'tank_compartment_id')) {
            Schema::table('delivery_allocations', function (Blueprint $table) {
                $table->dropIndex(['tank_compartment_id']);
                $table->dropColumn('tank_compartment_id');
            });
        }
    }
};