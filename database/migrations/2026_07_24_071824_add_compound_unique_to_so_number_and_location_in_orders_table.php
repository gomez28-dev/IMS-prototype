<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasIndex('orders', 'orders_so_number_unique')) {
                $table->dropUnique(['so_number']);
            }
            if (!Schema::hasIndex('orders', 'orders_so_number_location_unique')) {
                $table->unique(['so_number', 'location']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['so_number', 'location']);
            $table->unique('so_number');
        });
    }
};
