<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->foreignId('reverses_id')->nullable()->after('id')
                ->constrained('stock_ins')->nullOnDelete();
            $table->index('reverses_id');
        });

        // quantity was UNSIGNED — reversal entries store -X, so allow signed values.
        // Raw ALTER avoids requiring doctrine/dbal for ->change().
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `stock_ins` MODIFY `quantity` BIGINT NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropForeign(['reverses_id']);
            $table->dropIndex(['reverses_id']);
            $table->dropColumn('reverses_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `stock_ins` MODIFY `quantity` BIGINT UNSIGNED NOT NULL');
        }
    }
};
