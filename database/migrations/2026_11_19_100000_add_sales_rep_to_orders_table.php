<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'sales_rep')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('sales_rep', 50)->nullable()->after('terms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'sales_rep')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('sales_rep');
            });
        }
    }
};