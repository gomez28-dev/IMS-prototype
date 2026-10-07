<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Guarded so it is safe on a server where the earlier module-3 version of
     * this feature (migration 2026_09_07_000002, same table under a different
     * migration name) already created the table: instead of failing with
     * "table already exists", the existing table is brought up to this schema.
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_order_deliveries')) {
            $this->createTable();

            return;
        }

        $this->alignExistingTable();
    }

    private function createTable(): void
    {
        Schema::create('purchase_order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('delivery_channel', 40)->default('SUPPLIER_DOYEN_PICKUP'); 
            // SUPPLIER_CLIENT_PICKUP, SUPPLIER_STOCKS_DELIVERY, SUPPLIER_DOYEN_PICKUP, BUY_BACK_CLIENT_PICKUP, BUY_BACK_DOYEN_PICKUP, BUY_BACK_STOCKS_DELIVERY
            $table->string('order_type', 32)->default('PICK_UP'); // PICK_UP, DELIVERY
            $table->string('atl_type', 32)->default('DITC_ATL'); // DITC_ATL, CLIENT_ATL, NONE
            $table->string('dr_number', 64)->nullable();
            $table->string('atl_number', 64)->nullable();
            $table->string('client_atl_number', 64)->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->string('so_number', 64)->nullable();
            $table->string('product', 64);
            $table->integer('qty_to_receive');
            $table->date('receiving_date')->nullable();
            $table->string('driver_name', 128)->nullable();
            $table->string('plate_number', 64)->nullable();
            $table->string('location', 128)->nullable();
            $table->text('additional_remarks')->nullable();
            $table->string('status', 32)->default('Pending'); // Pending, Active, Completed, Cancelled
            $table->foreignId('prepared_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revised_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Bring a table created by the earlier migration up to this schema.
     */
    private function alignExistingTable(): void
    {
        if (!Schema::hasColumn('purchase_order_deliveries', 'delivery_channel')) {
            Schema::table('purchase_order_deliveries', function (Blueprint $table) {
                $table->string('delivery_channel', 40)->default('SUPPLIER_DOYEN_PICKUP')
                    ->after('purchase_order_id');
            });
        }

        if (!Schema::hasColumn('purchase_order_deliveries', 'atl_type')) {
            Schema::table('purchase_order_deliveries', function (Blueprint $table) {
                $table->string('atl_type', 32)->default('DITC_ATL')->after('order_type');
            });
        }

        if (!Schema::hasColumn('purchase_order_deliveries', 'client_atl_number')) {
            Schema::table('purchase_order_deliveries', function (Blueprint $table) {
                $table->string('client_atl_number', 64)->nullable()->after('atl_number');
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Widen order_type to this schema's size and make qty_to_receive a
        // signed integer to match what createTable() builds.
        DB::statement("ALTER TABLE purchase_order_deliveries MODIFY order_type VARCHAR(32) NOT NULL DEFAULT 'PICK_UP'");
        DB::statement('ALTER TABLE purchase_order_deliveries MODIFY qty_to_receive INT NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_deliveries');
    }
};
