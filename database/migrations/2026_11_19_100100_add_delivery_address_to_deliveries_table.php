<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('deliveries', 'delivery_address')) {
            Schema::table('deliveries', function (Blueprint $table) {
                $table->text('delivery_address')->nullable()->after('remarks');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('deliveries', 'delivery_address')) {
            Schema::table('deliveries', function (Blueprint $table) {
                $table->dropColumn('delivery_address');
            });
        }
    }
};