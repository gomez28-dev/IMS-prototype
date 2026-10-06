<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turns purchase_order_deliveries into first-class ATLs.
     *
     * Everything here is additive: the existing columns stay in place and keep
     * their current meaning, so the current Deliveries screens, approvals queue
     * and stock-in flow continue to work untouched until the new ATL screens
     * start reading these fields.
     *
     *   order_id          the Sales Order this ATL serves (null for Doyen Stocks)
     *   atl_category      FUEL_TRADE | BUY_BACK | DOYEN_STOCKS
     *   atl_source        DOYEN_ISSUED | CLIENT_PROVIDED
     *   approval_status   DRAFT | FOR_APPROVAL | APPROVED | REJECTED
     *   lift_status       UNLIFTED | LIFTED | CANCELLED
     *   issued_at / lifted_at / lifted_by / received_at / received_by
     */
    public function up(): void
    {
        $this->makePurchaseOrderNullable();

        $this->addColumn('order_id', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('purchase_order_id')
                ->constrained('orders')->nullOnDelete();
        });

        $this->addColumn('atl_category', function (Blueprint $table) {
            $table->string('atl_category', 24)->nullable()->after('order_id');
        });

        $this->addColumn('atl_source', function (Blueprint $table) {
            $table->string('atl_source', 24)->nullable()->after('atl_category');
        });

        $this->addColumn('approval_status', function (Blueprint $table) {
            $table->string('approval_status', 24)->nullable()->after('atl_source');
        });

        $this->addColumn('lift_status', function (Blueprint $table) {
            $table->string('lift_status', 24)->nullable()->after('status');
        });

        $this->addColumn('issued_at', function (Blueprint $table) {
            $table->timestamp('issued_at')->nullable()->after('atl_number');
        });

        $this->addColumn('lifted_at', function (Blueprint $table) {
            $table->timestamp('lifted_at')->nullable()->after('lift_status');
        });

        $this->addColumn('lifted_by', function (Blueprint $table) {
            $table->foreignId('lifted_by')->nullable()->after('lifted_at')
                ->constrained('admins')->nullOnDelete();
        });

        $this->addColumn('received_at', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('lifted_by');
        });

        $this->addColumn('received_by', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->after('received_at')
                ->constrained('admins')->nullOnDelete();
        });

        $this->addIndex('order_id');
        $this->addIndex('approval_status');
        $this->addIndex('lift_status');

        $this->backfillAtlFields();
    }

    /**
     * An ATL may draw from several supplier POs, so the single
     * purchase_order_id can no longer be mandatory. The column is kept for
     * legacy rows and as the primary PO.
     */
    private function makePurchaseOrderNullable(): void
    {
        if (!Schema::hasTable('purchase_order_deliveries')) {
            return;
        }

        $column = collect(Schema::getColumns('purchase_order_deliveries'))
            ->firstWhere('name', 'purchase_order_id');

        if ($column === null || $column['nullable'] === true) {
            return;
        }

        Schema::table('purchase_order_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->change();
        });
    }

    private function addColumn(string $column, callable $definition): void
    {
        if (!Schema::hasTable('purchase_order_deliveries') || Schema::hasColumn('purchase_order_deliveries', $column)) {
            return;
        }

        Schema::table('purchase_order_deliveries', $definition);
    }

    private function addIndex(string $column): void
    {
        if (!Schema::hasTable('purchase_order_deliveries') || Schema::hasColumn('purchase_order_deliveries', $column)) {
            return;
        }

        Schema::table('purchase_order_deliveries', function (Blueprint $table) use ($column) {
            $table->index($column);
        });
    }

    /**
     * Give existing delivery rows the new fields so legacy ATLs appear in the
     * right place instead of falling through every new filter.
     *
     * Only rows whose new field is still NULL are touched, so re-running this
     * migration can never overwrite a value an approver has since changed.
     */
    private function backfillAtlFields(): void
    {
        if (!Schema::hasTable('purchase_order_deliveries')) {
            return;
        }

        $rows = DB::table('purchase_order_deliveries')
            ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_deliveries.purchase_order_id')
            ->whereNull('purchase_order_deliveries.lift_status')
            ->orWhereNull('purchase_order_deliveries.approval_status')
            ->orWhereNull('purchase_order_deliveries.atl_category')
            ->orWhereNull('purchase_order_deliveries.atl_source')
            ->get([
                'purchase_order_deliveries.id',
                'purchase_order_deliveries.status',
                'purchase_order_deliveries.atl_type',
                'purchase_order_deliveries.client_atl_number',
                'purchase_order_deliveries.issued_at',
                'purchase_orders.po_type',
                'purchase_orders.request_status',
            ]);

        foreach ($rows as $row) {
            // Legacy status -> lift status. 'Active' and 'Completed' are the
            // old vocabulary for lifted/received work in progress.
            $liftStatus = match ($row->status) {
                'Cancelled' => 'CANCELLED',
                'Completed' => 'LIFTED',
                default => 'UNLIFTED',
            };

            $category = match ($row->po_type) {
                'FUEL_TRADE' => 'FUEL_TRADE',
                'BUY_BACK' => 'BUY_BACK',
                default => 'DOYEN_STOCKS',
            };

            $source = ($row->atl_type === 'CLIENT_ATL' || !empty($row->client_atl_number))
                ? 'CLIENT_PROVIDED'
                : 'DOYEN_ISSUED';

            // Already-approved legacy records must not reappear in the new
            // approval queue. A cancelled ATL was never approved, so it stays
            // a draft for the record.
            $approval = ($liftStatus === 'CANCELLED')
                ? 'DRAFT'
                : (($row->request_status === 'CONFIRMED' || $liftStatus === 'LIFTED')
                    ? 'APPROVED'
                    : 'DRAFT');

            $update = [];
            $setIfNull = function (string $column, $value) use (&$update, $row) {
                $update[$column] = $value;
            };

            $setIfNull('lift_status', $liftStatus);
            $setIfNull('atl_category', $category);
            $setIfNull('atl_source', $source);
            $setIfNull('approval_status', $approval);

            if (empty($row->issued_at)) {
                $update['issued_at'] = $row->issued_at;
            }

            DB::table('purchase_order_deliveries')
                ->where('id', $row->id)
                ->where(function ($q) {
                    // Re-assert the guard so only still-null fields change.
                    foreach (['lift_status', 'approval_status', 'atl_category', 'atl_source'] as $column) {
                        $q->orWhereNull($column);
                    }
                })
                ->update($update);
        }
    }

    public function down(): void
    {
        $columns = [
            'received_by', 'received_at', 'lifted_by', 'lifted_at',
            'issued_at', 'lift_status', 'approval_status', 'atl_source',
            'atl_category', 'order_id',
        ];

        $present = array_values(array_filter(
            $columns,
            fn ($c) => Schema::hasTable('purchase_order_deliveries')
                && Schema::hasColumn('purchase_order_deliveries', $c)
        ));

        if (empty($present)) {
            return;
        }

        foreach (['order_id', 'lifted_by', 'received_by'] as $foreign) {
            if (in_array($foreign, $present, true)) {
                Schema::table('purchase_order_deliveries', function (Blueprint $table) use ($foreign) {
                    $table->dropForeign([$foreign]);
                });
            }
        }

        Schema::table('purchase_order_deliveries', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
