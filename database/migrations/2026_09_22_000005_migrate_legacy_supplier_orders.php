<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update polymorphic modification requests
        if (Schema::hasTable('modification_requests')) {
            DB::table('modification_requests')
                ->where('requestable_type', 'App\Models\SupplierOrder')
                ->update(['requestable_type' => 'App\Models\PurchaseOrder']);
        }

        // 2. Migrate existing records from supplier_orders
        if (Schema::hasTable('supplier_orders') && Schema::hasTable('purchase_orders')) {
            $legacyOrders = DB::table('supplier_orders')->get();

            foreach ($legacyOrders as $so) {
                $exists = DB::table('purchase_orders')
                    ->where('remarks', 'like', "%[Migrated from Supplier Order #{$so->id}]%")
                    ->exists();

                if ($exists) {
                    continue;
                }

                $isCompleted = ($so->status === 'COMPLETED');
                $isCancelled = ($so->status === 'CANCELLED');
                $isPickup = ($so->status === 'UNLIFTED_PICKUP');

                $poId = DB::table('purchase_orders')->insertGetId([
                    'po_number' => $so->po_number,
                    'po_type' => 'STANDARD_REPLENISHMENT',
                    'supplier_name' => $so->supplier_name,
                    'qty_ordered' => $so->liters,
                    'warehouse_id' => $so->warehouse_id,
                    'request_status' => $isCompleted ? 'COMPLETED' : ($isCancelled ? 'COMPLETED' : 'FOR_DELIVERY'),
                    'status' => $isCompleted ? 'Fulfilled' : ($isCancelled ? 'Cancelled' : 'Pending'),
                    'requested_by' => $so->created_by,
                    'request_date' => $so->created_at,
                    'date_needed' => $so->created_at ? substr((string)$so->created_at, 0, 10) : date('Y-m-d'),
                    'requested_products' => json_encode([['product' => 'Diesel', 'quantity' => $so->liters]]),
                    'revised_at' => $so->revised_at,
                    'remarks' => trim(($so->remarks ? $so->remarks . ' ' : '') . "[Migrated from Supplier Order #{$so->id}]"),
                    'created_at' => $so->created_at ?? now(),
                    'updated_at' => $so->updated_at ?? now(),
                ]);

                DB::table('purchase_order_deliveries')->insert([
                    'purchase_order_id' => $poId,
                    'delivery_channel' => $isPickup ? 'SUPPLIER_DOYEN_PICKUP' : 'SUPPLIER_STOCKS_DELIVERY',
                    'order_type' => $isPickup ? 'PICK_UP' : 'DELIVERY',
                    'atl_type' => $isPickup ? 'DITC_ATL' : 'NONE',
                    'atl_number' => $isPickup ? $so->atl_dr_number : null,
                    'dr_number' => !$isPickup ? $so->atl_dr_number : null,
                    'reference_no' => 'MIGRATED-' . $so->id,
                    'product' => 'Diesel',
                    'qty_to_receive' => $so->liters,
                    'receiving_date' => $so->created_at ? substr((string)$so->created_at, 0, 10) : date('Y-m-d'),
                    'status' => $isCompleted ? 'Completed' : ($isCancelled ? 'Cancelled' : 'Active'),
                    'prepared_by' => $so->created_by,
                    'revised_at' => $so->revised_at,
                    'additional_remarks' => $so->remarks,
                    'created_at' => $so->created_at ?? now(),
                    'updated_at' => $so->updated_at ?? now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('modification_requests')) {
            DB::table('modification_requests')
                ->where('requestable_type', 'App\Models\PurchaseOrder')
                ->update(['requestable_type' => 'App\Models\SupplierOrder']);
        }
    }
};
