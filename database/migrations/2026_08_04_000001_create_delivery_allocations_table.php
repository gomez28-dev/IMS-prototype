<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('delivery_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('storage_tank_id')->constrained('storage_tanks')->cascadeOnDelete();
            $table->integer('quantity');
            $table->foreignId('assigned_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['delivery_id']);
            $table->index(['storage_tank_id']);
        });

        // Backfill existing single-tank assignments into allocation rows.
        DB::table('delivery_allocations')
            ->insertUsing(
                ['delivery_id', 'storage_tank_id', 'quantity', 'assigned_by', 'created_at', 'updated_at'],
                DB::table('deliveries')
                    ->whereNotNull('storage_tank_id')
                    ->select([
                        'id as delivery_id',
                        'storage_tank_id',
                        'qty_out as quantity',
                        'assigned_by',
                        'updated_at as created_at',
                        'updated_at as updated_at',
                    ])
            );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_allocations');
    }
};