<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One product line on a stock request.
     *
     * Module 2 requests used to keep their products in a requested_products
     * JSON column, which cannot answer "how much of this product has actually
     * arrived?". qty_received exists for that: Pending = Requested - Received.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_request_lines')) {
            $this->alignExistingTable();

            return;
        }

        Schema::create('stock_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_request_id')->constrained('stock_requests')->cascadeOnDelete();
            $table->string('product', 64);
            $table->integer('qty_requested')->default(0);
            $table->integer('qty_received')->default(0);
            $table->string('unit', 16)->default('LTRS');
            $table->timestamps();
        });

        Schema::table('stock_request_lines', function (Blueprint $table) {
            $table->index(['stock_request_id', 'product']);
        });
    }

    /**
     * Bring a table created by an earlier run of this migration up to schema.
     */
    private function alignExistingTable(): void
    {
        $columns = [
            'qty_requested' => fn (Blueprint $table) => $table->integer('qty_requested')->default(0),
            'qty_received' => fn (Blueprint $table) => $table->integer('qty_received')->default(0),
            'unit' => fn (Blueprint $table) => $table->string('unit', 16)->default('LTRS'),
        ];

        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('stock_request_lines', $column)) {
                Schema::table('stock_request_lines', $definition);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_lines');
    }
};