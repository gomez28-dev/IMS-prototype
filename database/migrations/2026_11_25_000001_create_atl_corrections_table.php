<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a correction changed, and who made it.
     *
     * Correcting an approved ATL reopens it for the VP, but the queue only ever
     * showed the new values: the VP had to take on trust that the correction was
     * the one asked for. One row per correction keeps the old and new value of
     * every field that moved, for as long as the ATL exists.
     */
    public function up(): void
    {
        if (Schema::hasTable('atl_corrections')) {
            return;
        }

        Schema::create('atl_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_delivery_id')
                ->constrained('purchase_order_deliveries')
                ->cascadeOnDelete();
            // An admins row is never deleted in normal use; if it ever is, the
            // correction stays and simply has no name against it.
            $table->foreignId('admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            // field_changes, not changes: Eloquent's Model already owns a
            // protected $changes property (the dirty attributes), so a column
            // called "changes" is shadowed inside the model.
            $table->json('field_changes');
            $table->timestamps();

            $table->index(['purchase_order_delivery_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atl_corrections');
    }
};
