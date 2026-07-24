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
        Schema::table('deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_by')->nullable()->after('remarks');
            $table->foreign('assigned_by')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropForeign(['assigned_by']);
            $table->dropColumn('assigned_by');
        });
    }
};
