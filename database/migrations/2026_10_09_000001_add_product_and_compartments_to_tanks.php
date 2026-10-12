<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every step is guarded so this converges on a database where the
        // change has already been applied, whether by a previous deploy or by
        // hand. Laravel tracks migrations by filename, but an old-schema
        // database may still be missing part of it.
        if (!Schema::hasColumn('storage_tanks', 'product_type')) {
            Schema::table('storage_tanks', function (Blueprint $table) {
                $table->string('product_type', 1)->nullable()->after('category'); // U, D, P (depots)
            });
        }

        if (!Schema::hasTable('tank_compartments')) {
            Schema::create('tank_compartments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('storage_tank_id')->constrained('storage_tanks')->cascadeOnDelete();
                $table->unsignedTinyInteger('compartment_no');
                $table->unsignedInteger('capacity');
                $table->string('product_type', 1)->nullable();
                $table->integer('contaminated_liters')->default(0);
                $table->string('contamination_note', 500)->nullable();
                $table->dateTime('contaminated_date')->nullable();
                $table->foreignId('contaminated_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamps();

                $table->unique(['storage_tank_id', 'compartment_no']);
            });
        }

        if (!Schema::hasColumn('stock_ins', 'tank_compartment_id')) {
            Schema::table('stock_ins', function (Blueprint $table) {
                $table->unsignedBigInteger('tank_compartment_id')->nullable()->after('storage_tank_id');
                $table->index('tank_compartment_id');
            });
        }

        if (!Schema::hasTable('stock_adjustments')) {
            Schema::create('stock_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('storage_tank_id')->constrained('storage_tanks')->cascadeOnDelete();
                $table->unsignedBigInteger('tank_compartment_id')->nullable()->index();
                $table->unsignedInteger('quantity');
                $table->string('reason', 60);
                $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');

        if (Schema::hasColumn('stock_ins', 'tank_compartment_id')) {
            Schema::table('stock_ins', function (Blueprint $table) {
                $table->dropIndex(['tank_compartment_id']);
                $table->dropColumn('tank_compartment_id');
            });
        }

        Schema::dropIfExists('tank_compartments');

        if (Schema::hasColumn('storage_tanks', 'product_type')) {
            Schema::table('storage_tanks', function (Blueprint $table) {
                $table->dropColumn('product_type');
            });
        }
    }
};