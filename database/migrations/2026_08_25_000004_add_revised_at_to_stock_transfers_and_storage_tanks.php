<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->timestamp('revised_at')->nullable()->after('transferred_by');
        });

        Schema::table('storage_tanks', function (Blueprint $table) {
            $table->timestamp('revised_at')->nullable()->after('remarks');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn('revised_at');
        });

        Schema::table('storage_tanks', function (Blueprint $table) {
            $table->dropColumn('revised_at');
        });
    }
};
