<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Nullable + nullOnDelete: existing historical orders won't have
            // a creator recorded, and if an admin account is ever deleted we
            // don't want that to cascade-delete their past orders.
            $table->foreignId('created_by')->nullable()->after('id')
                ->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
