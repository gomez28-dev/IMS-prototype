<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_orders', function (Blueprint $table) {
            $table->string('atl_dr_number')->nullable()->after('po_number');
        });

        DB::table('supplier_orders')->whereNull('atl_dr_number')->orderBy('id')->each(function ($row) {
            DB::table('supplier_orders')->where('id', $row->id)->update(['atl_dr_number' => 'LEGACY-' . $row->id]);
        });

        Schema::table('supplier_orders', function (Blueprint $table) {
            $table->unique('atl_dr_number');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_orders', function (Blueprint $table) {
            $table->dropUnique(['atl_dr_number']);
            $table->dropColumn('atl_dr_number');
        });
    }
};
