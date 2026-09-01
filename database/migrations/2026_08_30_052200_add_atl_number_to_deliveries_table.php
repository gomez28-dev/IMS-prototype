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
            // Nullable: only PICK-UP type deliveries are expected to carry an ATL
            // (Authority to Load) number. BIG TANKER / SMALL TANKER deliveries can
            // safely leave this blank, and all existing rows will simply be NULL.
            $table->string('atl_number')->nullable()->after('dr_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('atl_number');
        });
    }
};
