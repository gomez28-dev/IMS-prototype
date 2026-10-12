<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional on every order category, so this must stay nullable and is
        // guarded so it converges on databases where the column already exists.
        if (! Schema::hasColumn('orders', 'date_needed')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->date('date_needed')->nullable()->after('date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'date_needed')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('date_needed');
            });
        }
    }
};