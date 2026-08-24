<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('admins')
            ->where('role', 'editor')
            ->update(['role' => 'sales']);

        DB::table('admins')
            ->where('role', 'warehouse')
            ->update(['role' => 'ops_wh']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('admins')
            ->where('role', 'sales')
            ->update(['role' => 'editor']);

        DB::table('admins')
            ->where('role', 'ops_wh')
            ->update(['role' => 'warehouse']);
    }
};
