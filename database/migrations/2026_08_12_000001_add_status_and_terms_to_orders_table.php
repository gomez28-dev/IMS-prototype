<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 20)->default('Active')->after('clearing_status');
            $table->string('terms', 64)->nullable()->after('status');
        });

        DB::table('orders')
            ->where('so_number', 'LIKE', '%CANCEL ORDER%')
            ->update(['status' => 'Cancelled']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['status', 'terms']);
        });
    }
};
