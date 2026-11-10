<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_unit_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_tank_id')->unique()->constrained('storage_tanks')->cascadeOnDelete();
            $table->string('status', 24)->default('garage');
            $table->string('target_type', 16)->nullable();         // client | supplier
            $table->string('target_name', 150)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('logistics_unit_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_tank_id')->constrained('storage_tanks')->cascadeOnDelete();
            $table->string('status', 24);
            $table->string('label', 64);
            $table->string('target_type', 16)->nullable();
            $table->string('target_name', 150)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['storage_tank_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_unit_status_logs');
        Schema::dropIfExists('logistics_unit_statuses');
    }
};
