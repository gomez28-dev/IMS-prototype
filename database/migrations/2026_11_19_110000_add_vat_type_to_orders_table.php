<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'vat_type')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('vat_type', 10)->default('VAT_EX')->after('sales_rep');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'vat_type')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('vat_type');
            });
        }
    }
};