<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('po_number', 64)->unique()->nullable()->after('so_number');
            $table->string('clearing_status', 20)->default('Pending')->after('po_number');
            $table->unique('so_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['so_number']);
            $table->dropColumn(['po_number', 'clearing_status']);
        });
    }
};
