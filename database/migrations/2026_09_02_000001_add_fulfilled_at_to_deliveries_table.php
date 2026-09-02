<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('fulfilled_at')->nullable()->after('fulfilled_by');
            $table->index('fulfilled_at');
            $table->index('dr_number');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['fulfilled_at']);
            $table->dropIndex(['dr_number']);
            $table->dropColumn('fulfilled_at');
        });
    }
};
