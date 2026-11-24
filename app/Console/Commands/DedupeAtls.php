<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrderDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds ATLs filed more than once on the same purchase order, and optionally
 * removes the extra rows.
 *
 * The save path used to insert a delivery row on every save, so one ATL could
 * be filed twice: two rows on one PO, the same product and volume, and - when
 * DITC had not issued a number yet - both reading "Not yet issued" in the VP
 * queue. New saves are refused now, but rows already in the database are not:
 * they still hold volume on their PO and still sit in the queue.
 *
 * Reporting is the default. --apply deletes the duplicates together with their
 * allocations, because the duplicate allocation is what double-counts the
 * volume the PO appears to have committed. --ids narrows that to the rows
 * picked from the report, so one duplicate can be removed without touching
 * every other group in the same run.
 */
class DedupeAtls extends Command
{
    protected $signature = 'stock-orders:dedupe-atls
                            {--apply : Delete the duplicate rows instead of only reporting them}
                            {--po= : Only look at one purchase order id}
                            {--ids= : Only delete these ATL ids from the report, e.g. --ids=138,152}';

    protected $description = 'Report ATLs filed more than once on the same PO, and optionally delete the extra rows';

    public function handle(): int
    {
        $rows = PurchaseOrderDelivery::with(['purchaseOrder', 'allocations'])
            ->where('status', '!=', 'Cancelled')
            ->where(function ($q) {
                $q->whereNull('approval_status')
                    ->orWhere('approval_status', '!=', PurchaseOrderDelivery::APPROVAL_REJECTED);
            })
            ->when($this->option('po'), fn ($q, $po) => $q->where('purchase_order_id', (int) $po))
            ->orderBy('id')
            ->get();

        $groups = $rows
            ->groupBy(fn (PurchaseOrderDelivery $atl) => $this->identityKey($atl))
            ->filter(fn (Collection $group) => $group->count() > 1);

        $this->info('Duplicate ATL report');
        $this->line(str_repeat('-', 78));

        if ($groups->isEmpty()) {
            $this->line('  No duplicate ATLs found.');

            return self::SUCCESS;
        }

        // Which ids the run was pointed at. Null means "every safe duplicate",
        // which is what --apply alone has always done.
        $chosen = $this->chosenIds();

        if ($chosen === false) {
            return self::FAILURE;
        }

        $keepers = [];
        $blocked = [];
        $candidates = [];

        foreach ($groups as $group) {
            $keeper = $this->keeper($group);
            $keepers[$keeper->id] = true;

            foreach ($group as $atl) {
                if ($atl->is($keeper)) {
                    continue;
                }

                $blocked[$atl->id] = $this->blockedReason($atl);

                if ($blocked[$atl->id] === null) {
                    $candidates[$atl->id] = $atl;
                }
            }
        }

        if ($chosen !== null && ! $this->checkChosen($chosen, $rows, $keepers, $candidates, $blocked)) {
            return self::FAILURE;
        }

        $table = [];
        $deletable = [];

        foreach ($groups as $group) {
            $keeper = $this->keeper($group);

            foreach ($group as $atl) {
                $selected = $chosen === null || in_array($atl->id, $chosen, true);

                if ($atl->is($keeper)) {
                    $action = 'keep (original)';
                } elseif ($blocked[$atl->id] !== null) {
                    $action = 'review: '.$blocked[$atl->id];
                } elseif (! $selected) {
                    $action = 'duplicate (not selected)';
                } else {
                    $action = 'delete with '.$atl->allocations->count().' allocation(s)';
                    $deletable[$atl->id] = $atl;
                }

                $table[] = [
                    $atl->purchaseOrder?->po_number ?? '#'.$atl->purchase_order_id,
                    $atl->id,
                    $atl->atl_number ?: ($atl->client_atl_number ?: '(not yet issued)'),
                    $atl->product,
                    number_format((int) $atl->qty_to_receive),
                    optional($atl->receiving_date)->format('d M Y') ?? '-',
                    $atl->status,
                    $atl->approval_status ?: '-',
                    optional($atl->created_at)->format('d M Y g:i A') ?? '-',
                    $action,
                ];
            }
        }

        $this->table(
            ['PO', 'ATL id', 'ATL number', 'Product', 'Qty', 'Receiving', 'Status', 'Approval', 'Saved', 'Action'],
            $table
        );

        $deletable = array_values($deletable);
        sort($deletable);

        if ($chosen !== null) {
            $this->line(sprintf(
                '  %d duplicate group(s); %d of the %d id(s) you picked are safe to delete.',
                $groups->count(),
                count($deletable),
                count($chosen)
            ));
        } else {
            $this->line(sprintf(
                '  %d duplicate group(s); %d row(s) safe to delete.',
                $groups->count(),
                count($deletable)
            ));
        }

        if (! $this->option('apply')) {
            $this->line('');

            if ($chosen !== null) {
                $this->info('Report only. Re-run with --apply --ids='.implode(',', $chosen)
                    .' to delete the '.count($deletable).' selected row(s).');
            } else {
                $this->info('Report only. Re-run with --apply to delete the '.count($deletable).' duplicate row(s).');
                $this->line('  To remove only some of them, pass the ids from this report: '
                    .'--apply --ids='.implode(',', array_slice(array_column($deletable, 'id'), 0, 3)));
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($deletable) {
            foreach ($deletable as $atl) {
                // The allocation is what puts this ATL's volume on the PO, so it
                // leaves with the row; otherwise the PO keeps counting a load
                // that no longer exists.
                $atl->allocations()->delete();
                $atl->delete();
            }
        });

        $this->info('Deleted '.count($deletable).' duplicate ATL row(s) and their allocations.');
        $this->line('  PO balances and the VP ATL queue now count each load once.');

        return self::SUCCESS;
    }

    /**
     * The ids named with --ids, as a list. Null when the option was not used,
     * false when it could not be read - the caller stops rather than deleting
     * something the operator did not name.
     *
     * @return array<int, int>|false|null
     */
    private function chosenIds(): array|false|null
    {
        $raw = $this->option('ids');

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $ids = [];

        foreach (explode(',', (string) $raw) as $piece) {
            $piece = trim($piece);

            if ($piece === '' || ! ctype_digit($piece)) {
                $this->error("--ids expects comma-separated ATL ids, but got \"{$piece}\".");

                return false;
            }

            $ids[(int) $piece] = (int) $piece;
        }

        return array_values($ids);
    }

    /**
     * Every picked id has to be a duplicate the report itself would delete.
     * A typo, the original the report keeps, or a row that has already moved
     * stock is refused rather than silently skipped, so a run never deletes
     * something other than what was picked.
     *
     * @param  array<int, int>  $chosen
     * @param  Collection<int, PurchaseOrderDelivery>  $rows
     * @param  array<int, bool>  $keepers
     * @param  array<int, PurchaseOrderDelivery>  $candidates
     * @param  array<int, string|null>  $blocked
     */
    private function checkChosen(array $chosen, Collection $rows, array $keepers, array $candidates, array $blocked): bool
    {
        $ok = true;

        foreach ($chosen as $id) {
            if (! $rows->contains('id', $id)) {
                $this->error("ATL #{$id} is not a live duplicate in this report, so it will not be deleted.");
                $ok = false;

                continue;
            }

            if (isset($keepers[$id])) {
                $this->error("ATL #{$id} is the original the report keeps; pick the duplicate row beside it instead.");
                $ok = false;

                continue;
            }

            if (! isset($candidates[$id])) {
                $this->error("ATL #{$id} cannot be deleted: ".($blocked[$id] ?? 'not a deletable duplicate').'.');
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * The same identity rule the save path refuses on: an ATL number identifies
     * the document, and a blank one is identified by its shape - the PO, the
     * order or request it serves, the product, volume and channel.
     */
    private function identityKey(PurchaseOrderDelivery $atl): string
    {
        $po = $atl->purchase_order_id;
        $atlNumber = trim((string) $atl->atl_number);
        $clientNumber = trim((string) $atl->client_atl_number);

        if ($atlNumber !== '') {
            return $po.'|ATL|'.$atlNumber;
        }

        if ($clientNumber !== '') {
            return $po.'|CLIENT|'.$clientNumber;
        }

        return $po.'|SHAPE|'.implode('|', [
            $atl->order_id ?? '-',
            $atl->request_id ?? '-',
            (string) $atl->order_type,
            (string) $atl->delivery_channel,
            (string) $atl->product,
            (int) $atl->qty_to_receive,
        ]);
    }

    /**
     * The row to keep: a numbered ATL outranks a blank one, then the earliest.
     */
    private function keeper(Collection $group): PurchaseOrderDelivery
    {
        return $group
            ->sortBy(fn (PurchaseOrderDelivery $atl) => (trim((string) ($atl->atl_number ?: $atl->client_atl_number)) === '' ? '1' : '0')
                .str_pad((string) $atl->id, 12, '0', STR_PAD_LEFT))
            ->first();
    }

    /**
     * Why a duplicate cannot simply be deleted. Anything that already produced
     * stock or moved the ATL forward is a human decision, not a cleanup.
     */
    private function blockedReason(PurchaseOrderDelivery $atl): ?string
    {
        if ($atl->stockIns()->exists()) {
            return 'has stock-in records';
        }

        if ($atl->received_at) {
            return 'already received';
        }

        if ($atl->lift_status === PurchaseOrderDelivery::LIFT_LIFTED || $atl->lifted_at) {
            return 'already lifted';
        }

        if ($atl->approval_status === PurchaseOrderDelivery::APPROVAL_APPROVED) {
            return 'already approved';
        }

        return null;
    }
}
