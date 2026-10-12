<?php

use App\Support\StockRequestBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Copy existing stock requests out of purchase_orders.
 *
 * A legacy request is a purchase_orders row with no PO number. These are
 * copied into stock_requests and stock_request_lines, keeping the original
 * rows untouched and linked through legacy_purchase_order_id, so nothing is
 * deleted and the existing Purchasing and receive flows keep working.
 *
 * Idempotent: a request is only copied once, and already-linked rows are
 * skipped, so re-running this is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new StockRequestBackfill)->run();
    }

    public function down(): void
    {
        // The original purchase_orders rows were never touched, so there is
        // nothing to restore here.
    }
};