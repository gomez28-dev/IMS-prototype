<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_trips', function (Blueprint $table) {
            $table->id();
            $table->string('trip_no', 32)->nullable()->unique();   // TRP-YYMMDD-{id}, set after insert
            $table->foreignId('storage_tank_id')->constrained('storage_tanks')->cascadeOnDelete();
            $table->string('trip_type', 16)->default('delivery');  // delivery | pickup
            $table->string('status', 16)->default('scheduled');    // scheduled | completed | cancelled
            $table->date('scheduled_date');
            $table->string('supplier_name', 150)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('helper_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index('scheduled_date');
            $table->index(['storage_tank_id', 'scheduled_date']);
        });

        Schema::create('logistics_trip_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_trip_id')->constrained('logistics_trips')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('client_name', 150);                    // snapshot, survives delivery deletion
            $table->string('address', 255)->nullable();
            $table->string('so_no', 64)->nullable();
            $table->string('dr_no', 64)->nullable();
            $table->string('wb_no', 64)->nullable();
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('status_eta', 64)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['logistics_trip_id', 'sort']);
            $table->index('delivery_id');
            $table->index('status');
        });

        Schema::create('logistics_stop_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_trip_stop_id')->constrained('logistics_trip_stops')->cascadeOnDelete();
            $table->unsignedTinyInteger('compartment_no');
            $table->string('product_type', 1)->nullable();         // D | U | P
            $table->unsignedInteger('quantity');                   // litres
            $table->timestamps();

            $table->unique(['logistics_trip_stop_id', 'compartment_no'], 'stop_lines_compartment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_stop_lines');
        Schema::dropIfExists('logistics_trip_stops');
        Schema::dropIfExists('logistics_trips');
    }
};
