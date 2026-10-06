<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports on, and optionally links, the legacy Fuel Trade / Buy Back records.
 *
 * Older entries were stored as purchase_orders rows of type FUEL_TRADE or
 * BUY_BACK, with their ATL details in purchase_order_deliveries. The redesign
 * treats the PO as a supplier contract and the delivery as the ATL, so those
 * ATLs need to point at the sales order they serve.
 *
 * This reports first and only changes data when --apply is passed, because the
 * production row counts decide whether legacy rows should be linked or reset.
 * Ambiguous rows are never guessed at; they are listed for a human to decide.
 */
class AuditLegacyAtls extends Command
{
    protected $signature = 'stock-orders:legacy-atl
                            {--apply : Link unlinked ATLs to their sales order instead of only reporting}';

    protected $description = 'Report on legacy Fuel Trade / Buy Back records, and optionally link their ATLs to their sales order';

    public function handle(): int
    {
        $this->info('Legacy Fuel Trade / Buy Back audit');
        $this->line(str_repeat('-', 64));

        $legacyPos = PurchaseOrder::whereIn('po_type', ['FUEL_TRADE', 'BUY_BACK'])->get();

        $this->section('Purchase Orders');
        $this->keyValue('FUEL_TRADE POs', $legacyPos->where('po_type', 'FUEL_TRADE')->count());
        $this->keyValue('BUY_BACK POs', $legacyPos->where('po_type', 'BUY_BACK')->count());

        $atls = PurchaseOrderDelivery::query()
            ->whereIn('atl_type', ['DITC_ATL', 'CLIENT_ATL'])
            ->orWhereNotNull('atl_number')
            ->orWhereNotNull('client_atl_number')
            ->get();

        $this->section('ATL records');
        $this->keyValue('Total ATL-like rows', $atls->count());
        $this->keyValue('Already linked to an order', $atls->whereNotNull('order_id')->count());
        $this->keyValue('Missing a sales order link', $atls->whereNull('order_id')->count());

        // ---- Work out what each unlinked ATL belongs to -------------------
        $ordersBySo = Order::pluck('id', 'so_number');

        // Orders that point at each PO, used only when the SO number is absent.
        $ordersByPo = Order::query()
            ->whereNotNull('linked_purchase_order_id')
            ->get(['id', 'so_number', 'linked_purchase_order_id'])
            ->groupBy('linked_purchase_order_id');

        $linkable = new Collection();
        $ambiguous = new Collection();

        foreach ($atls->whereNull('order_id') as $atl) {
            $orderId = null;
            $reason = null;

            // 1. Exact sales order number on the ATL.
            $soNumber = trim((string) $atl->so_number);
            if ($soNumber !== '' && isset($ordersBySo[$soNumber])) {
                $orderId = $ordersBySo[$soNumber];
                $reason = 'matched ATL sales order number';
            }

            // 2. Exactly one sales order is attached to the parent PO.
            if ($orderId === null && $atl->purchase_order_id) {
                $candidates = $ordersByPo[$atl->purchase_order_id] ?? collect();
                if ($candidates->count() === 1) {
                    $orderId = $candidates->first()->id;
                    $reason = 'only order attached to the purchase order';
                }
            }

            if ($orderId === null) {
                $ambiguous->push($atl);
                continue;
            }

            $linkable->push(['atl' => $atl, 'order_id' => $orderId, 'reason' => $reason]);
        }

        $this->section('Matching');
        $this->keyValue('Can be linked confidently', count($linkable));
        $this->keyValue('Ambiguous, need a decision', count($ambiguous));

        if ($this->shouldExplainAmbiguity($ambiguous)) {
            $this->line('');
            $this->warn('Ambiguous ATLs (not linked):');
            foreach ($ambiguous as $atl) {
                $this->line(sprintf(
                    '  - ATL #%s | so_number=%s | PO=%s | qty=%s L',
                    $atl->id,
                    $atl->so_number ?: '(none)',
                    $atl->purchaseOrder->po_number ?? '(none)',
                    number_format((float) $atl->qty_to_receive)
                ));
            }
        }

        // ---- Report the odd shapes worth a human look ---------------------
        $sharedPos = $this->purchaseOrdersSharingOrders();
        if ($sharedPos->isNotEmpty()) {
            $this->line('');
            $this->warn('Purchase orders attached to more than one sales order:');
            foreach ($sharedPos as $poNumber => $orderCount) {
                $this->line(sprintf('  - PO %s: %d sales orders linked', $poNumber, $orderCount));
            }
            $this->line('  These look like test or duplicate data; decide whether to reset them.');
        }

        if (! $this->option('apply')) {
            $this->line('');
            $this->info('Report only. Re-run with --apply to link the ' . count($linkable) . ' unambiguous ATL(s).');
            return self::SUCCESS;
        }

        // ---- Apply --------------------------------------------------------
        $this->line('');
        $this->info('Linking ATLs to their sales orders...');

        DB::transaction(function () use ($linkable) {
            foreach ($linkable as $row) {
                /** @var PurchaseOrderDelivery $atl */
                $atl = $row['atl'];
                $atl->update(['order_id' => $row['order_id']]);
            }
        });

        $this->info('Linked ' . count($linkable) . ' ATL(s).');
        $this->keyValue('Still unlinked', PurchaseOrderDelivery::whereNull('order_id')
            ->where(function ($q) {
                $q->whereNotNull('atl_number')->orWhereNotNull('client_atl_number');
            })->count());

        return self::SUCCESS;
    }

    /**
     * Purchase orders that more than one sales order points at. A supplier PO
     * may legitimately serve several ATLs, so this is reported as something to
     * look at rather than treated as an error.
     *
     * @return Collection<string, int>
     */
    private function purchaseOrdersSharingOrders(): Collection
    {
        return Order::query()
            ->whereNotNull('linked_purchase_order_id')
            ->get(['linked_purchase_order_id'])
            ->groupBy('linked_purchase_order_id')
            ->filter(fn ($group) => $group->count() > 1)
            ->mapWithKeys(function ($group, $poId) {
                $po = PurchaseOrder::find($poId);
                return [$po?->po_number ?? "PO#{$poId}" => $group->count()];
            });
    }

    private function shouldExplainAmbiguity(Collection $ambiguous): bool
    {
        return $ambiguous->isNotEmpty();
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line($title);
    }

    private function keyValue(string $label, $value): void
    {
        $this->line(sprintf('  %-34s %s', $label, $value));
    }
}
