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
        Schema::create('modification_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('requestable'); // requestable_type, requestable_id (Order, Delivery, StockTransfer)
            $table->foreignId('requested_by')->constrained('admins')->cascadeOnDelete();
            $table->json('changes'); // { field: { old: ..., new: ... }, ... }
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('PENDING'); // PENDING, APPROVED, REJECTED
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modification_requests');
    }
};
