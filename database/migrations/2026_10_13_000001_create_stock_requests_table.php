<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedicated Module 2 stock requests.
     *
     * Requests used to live in purchase_orders as placeholder rows with no PO
     * number, no supplier and no value, which made "is this a request or a
     * purchase order?" a guess based on a null column. They get their own
     * tables instead.
     *
     * legacy_purchase_order_id keeps the old row reachable so the existing
     * Purchasing and receive flows keep working during the changeover, and so
     * nothing has to be deleted from purchase_orders.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_requests')) {
            $this->alignExistingTable();

            return;
        }

        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            // Real request number, shown as REQ-001. Assigned by the model,
            // not by the database, matching how PO numbers are handled.
            $table->string('request_number', 64)->nullable();
            // PICKUP or DELIVERY: how the fuel arrives.
            $table->string('request_type', 32)->default('PICKUP');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('request_date')->useCurrent();
            $table->date('date_needed')->nullable();
            $table->text('remarks')->nullable();
            // REQUESTED, RECEIVED, CONFIRMED, FOR_DELIVERY, COMPLETED.
            $table->string('status', 32)->default('REQUESTED');
            // Purchasing picking the request up, which the M2 list reports
            // separately from the quantities actually received.
            $table->timestamp('purchasing_received_at')->nullable();
            $table->foreignId('purchasing_received_by')->nullable()->constrained('admins')->nullOnDelete();
            // The purchase_orders row this request was backfilled from.
            $table->foreignId('legacy_purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('stock_requests', function (Blueprint $table) {
            $table->index('request_number');
            $table->index('status');
            $table->index('legacy_purchase_order_id');
        });
    }

    /**
     * Bring a table created by an earlier run of this migration up to schema.
     */
    private function alignExistingTable(): void
    {
        $columns = [
            'request_number' => fn (Blueprint $table) => $table->string('request_number', 64)->nullable(),
            'request_type' => fn (Blueprint $table) => $table->string('request_type', 32)->default('PICKUP'),
            'date_needed' => fn (Blueprint $table) => $table->date('date_needed')->nullable(),
            'remarks' => fn (Blueprint $table) => $table->text('remarks')->nullable(),
            'status' => fn (Blueprint $table) => $table->string('status', 32)->default('REQUESTED'),
            'purchasing_received_at' => fn (Blueprint $table) => $table->timestamp('purchasing_received_at')->nullable(),
            'purchasing_received_by' => fn (Blueprint $table) => $table->foreignId('purchasing_received_by')->nullable()->constrained('admins')->nullOnDelete(),
            'legacy_purchase_order_id' => fn (Blueprint $table) => $table->foreignId('legacy_purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete(),
        ];

        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('stock_requests', $column)) {
                Schema::table('stock_requests', $definition);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requests');
    }
};