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
     * this feature (migration 2026_09_07_000001, same table under a different
     * migration name) already created the table: instead of failing with
     * "table already exists", the existing table is brought up to this schema.
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_orders')) {
            $this->createTable();

            return;
        }

        $this->alignExistingTable();
    }

    private function createTable(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 64)->nullable();
            $table->string('po_type', 32)->default('STANDARD_REPLENISHMENT'); // STANDARD_REPLENISHMENT, FUEL_TRADE, BUY_BACK
            $table->string('supplier_name', 128)->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('linked_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->integer('qty_ordered');
            $table->string('request_status', 32)->default('REQUESTED'); // REQUESTED, RECEIVED, CONFIRMED, FOR_DELIVERY, COMPLETED
            $table->string('status', 32)->default('Pending'); // Pending, Fulfilled, Cancelled
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('request_date')->useCurrent();
            $table->date('date_needed')->nullable();
            $table->json('requested_products')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revised_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Bring a table created by the earlier migration up to this schema.
     */
    private function alignExistingTable(): void
    {
        if (!Schema::hasColumn('purchase_orders', 'po_type')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->string('po_type', 32)->default('STANDARD_REPLENISHMENT')->after('id');
            });
        }

        if (!Schema::hasColumn('purchase_orders', 'client_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('po_type')
                    ->constrained('clients')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('purchase_orders', 'linked_order_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->foreignId('linked_order_id')->nullable()->after('client_id')
                    ->constrained('orders')->nullOnDelete();
            });
        }

        // The remaining columns createTable() builds. A table left behind by
        // the earlier migration can be missing any of them, and the page code
        // reads all of them, so add whatever is absent rather than assuming
        // the old shape.
        if (!Schema::hasColumn('purchase_orders', 'date_needed')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->date('date_needed')->nullable()->after('request_date');
            });
        }

        if (!Schema::hasColumn('purchase_orders', 'requested_products')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->json('requested_products')->nullable()->after('date_needed');
            });
        }

        if (!Schema::hasColumn('purchase_orders', 'approved_by')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->foreignId('approved_by')->nullable()->after('requested_products')
                    ->constrained('admins')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('purchase_orders', 'approved_at')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            });
        }

        if (!Schema::hasColumn('purchase_orders', 'revised_at')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->timestamp('revised_at')->nullable()->after('approved_at');
            });
        }

        if (!Schema::hasColumn('purchase_orders', 'remarks')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->text('remarks')->nullable()->after('revised_at');
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // The earlier schema made these mandatory and unique'd po_number; this
        // schema does neither (fuel-trade POs have no warehouse, and a number
        // may be reissued), so relax them to match what this migration creates.
        DB::statement('ALTER TABLE purchase_orders MODIFY warehouse_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE purchase_orders MODIFY requested_by BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE purchase_orders MODIFY qty_ordered INT NOT NULL');

        // NOTE: a purchase_orders_po_number_unique index found on an older
        // database is deliberately LEFT IN PLACE. Dropping it here would
        // discard the one guarantee that a PO number identifies a single
        // Purchase Order. Uniqueness is enforced at the application layer
        // (see StockOrderController::updateRequest), and a database-level
        // UNIQUE constraint can be added later once production has been
        // audited for existing duplicates.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
