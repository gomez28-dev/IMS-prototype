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
        Schema::table('storage_tanks', function (Blueprint $table) {
            $table->string('category', 20)->default('depot')->after('name');
            $table->boolean('is_contaminated')->default(false)->after('max_capacity');
            $table->integer('contaminated_liters')->default(0)->after('is_contaminated');
            $table->dateTime('contaminated_date')->nullable()->after('contaminated_liters');
            $table->foreignId('contaminated_by')->nullable()->constrained('admins')->nullOnDelete()->after('contaminated_date');
            $table->text('remarks')->nullable()->after('contaminated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('storage_tanks', function (Blueprint $table) {
            $table->dropForeign(['contaminated_by']);
            $table->dropColumn([
                'category',
                'is_contaminated',
                'contaminated_liters',
                'contaminated_date',
                'contaminated_by',
                'remarks',
            ]);
        });
    }
};
