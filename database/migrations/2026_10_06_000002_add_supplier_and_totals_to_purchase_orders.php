<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier PO fields: a real supplier link plus the header, totals and
     * approval-identity columns the Purchase Order form and PDF need.
     *
     * Every column is added only if missing, so this is safe on a server where
     * a partially applied version of these changes already exists. Nothing
     * here is required by existing code yet, so the app keeps working until the
     * PO form starts using them.
     */
    public function up(): void
    {
        $this->addColumn('supplier_id', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier_name')
                ->constrained('suppliers')->nullOnDelete();
        });

        $this->addColumn('attention', function (Blueprint $table) {
            $table->string('attention', 128)->nullable()->after('supplier_id');
        });

        $this->addColumn('terms', function (Blueprint $table) {
            $table->string('terms', 128)->nullable()->after('attention');
        });

        $this->addColumn('po_date', function (Blueprint $table) {
            $table->date('po_date')->nullable()->after('terms');
        });

        // Money columns. These mirror the sample PO layout, where VAT is
        // entered by hand, so every total is a stored manual value rather than
        // something derived at save time.
        $this->addColumn('total_amount', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->default(0)->after('po_date');
        });

        $this->addColumn('vatable_sales_amount', function (Blueprint $table) {
            $table->decimal('vatable_sales_amount', 15, 2)->default(0)->after('total_amount');
        });

        $this->addColumn('vat_amount', function (Blueprint $table) {
            $table->decimal('vat_amount', 15, 2)->default(0)->after('vatable_sales_amount');
        });

        $this->addColumn('less_w_tax', function (Blueprint $table) {
            $table->decimal('less_w_tax', 15, 2)->default(0)->after('vat_amount');
        });

        $this->addColumn('net_payable_amount', function (Blueprint $table) {
            $table->decimal('net_payable_amount', 15, 2)->default(0)->after('less_w_tax');
        });

        // Who prepared / approved the PO, for the printed signature lines.
        $this->addColumn('prepared_by', function (Blueprint $table) {
            $table->foreignId('prepared_by')->nullable()->after('net_payable_amount')
                ->constrained('admins')->nullOnDelete();
        });

        $this->linkSuppliers();
        $this->backfillTotals();
    }

    /**
     * Run the given column definition only when the column is absent.
     */
    private function addColumn(string $column, callable $definition): void
    {
        if (!Schema::hasTable('purchase_orders') || Schema::hasColumn('purchase_orders', $column)) {
            return;
        }

        Schema::table('purchase_orders', $definition);
    }

    /**
     * Point each Purchase Order at the supplier matching its supplier_name.
     * supplier_name stays as a legacy mirror so anything still reading it
     * keeps working.
     */
    private function linkSuppliers(): void
    {
        if (!Schema::hasTable('suppliers') || !Schema::hasColumn('purchase_orders', 'supplier_id')) {
            return;
        }

        $suppliers = DB::table('suppliers')->get(['id', 'company_name']);

        // Two suppliers can share a company name at different locations, so a
        // PO is matched by name against all candidates and the first
        // (ordered by id) wins. That keeps the legacy free-text name linking
        // deterministic without overwriting an already-linked PO.
        $suppliers = DB::table('suppliers')
            ->orderBy('id')
            ->get(['id', 'company_name'])
            ->groupBy(fn ($s) => trim(preg_replace('/\s+/u', ' ', $s->company_name)));

        $unlinked = DB::table('purchase_orders')
            ->whereNull('supplier_id')
            ->whereNotNull('supplier_name')
            ->orderBy('id')
            ->get(['id', 'supplier_name']);

        foreach ($unlinked as $po) {
            $name = trim(preg_replace('/\s+/u', ' ', $po->supplier_name));

            // Exact match first, then fall back to a case-insensitive match.
            $matches = $suppliers->get($name)
                ?? $suppliers->first(fn ($group, $company) => strcasecmp($company, $name) === 0);

            if ($matches === null || $matches->isEmpty()) {
                continue;
            }

            DB::table('purchase_orders')
                ->where('id', $po->id)
                ->update(['supplier_id' => $matches->first()->id]);
        }
    }

    /**
     * Give existing Purchase Orders a total and net payable so the new columns
     * do not read as zero. Derived from their item lines where available.
     */
    private function backfillTotals(): void
    {
        if (!Schema::hasTable('purchase_order_items')) {
            return;
        }

        $totals = DB::table('purchase_order_items')
            ->selectRaw('purchase_order_id, SUM(quantity_ordered * unit_price) AS line_total')
            ->groupBy('purchase_order_id')
            ->pluck('line_total', 'purchase_order_id');

        foreach ($totals as $purchaseOrderId => $lineTotal) {
            DB::table('purchase_orders')
                ->where('id', $purchaseOrderId)
                ->where('total_amount', 0)
                ->update([
                    'total_amount' => round((float) $lineTotal, 2),
                    'net_payable_amount' => round((float) $lineTotal, 2),
                ]);
        }
    }

    public function down(): void
    {
        $columns = [
            'prepared_by', 'net_payable_amount', 'less_w_tax', 'vat_amount',
            'vatable_sales_amount', 'total_amount', 'po_date', 'terms', 'attention', 'supplier_id',
        ];

        $present = array_values(array_filter(
            $columns,
            fn ($c) => Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', $c)
        ));

        if (empty($present)) {
            return;
        }

        // Foreign keys must go before their columns, otherwise MySQL refuses to
        // drop a column the constraint still depends on.
        if (in_array('supplier_id', $present, true)) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });
        }

        if (in_array('prepared_by', $present, true)) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropForeign(['prepared_by']);
            });
        }

        Schema::table('purchase_orders', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
