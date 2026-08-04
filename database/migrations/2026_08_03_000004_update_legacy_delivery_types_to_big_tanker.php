<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('deliveries')
            ->where('type', 'DELIVERY')
            ->update(['type' => 'BIG TANKER']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('deliveries')
            ->where('type', 'BIG TANKER')
            ->update(['type' => 'DELIVERY']);
    }
};
