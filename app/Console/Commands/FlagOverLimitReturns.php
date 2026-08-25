<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ModificationRequest;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class FlagOverLimitReturns extends Command
{
    /**
     * One-time remediation: find Return records whose quantity exceeds the site's
     * outstanding borrowed balance and create a PENDING ModificationRequest for each,
     * proposing the maximum legitimate quantity — so the correction goes through the
     * audited Module 2 approval flow instead of a direct DB edit.
     */
    protected $signature = 'transfers:flag-over-returns';

    protected $description = 'Create Modification Requests for Return transfers exceeding the outstanding borrowed balance of their site';

    public function handle(): int
    {
        $returns = StockTransfer::where('type', 'return')->get();
        $flagged = 0;

        foreach ($returns as $return) {
            $outstandingExclSelf = StockTransfer::getOutstandingBalanceFor($return->destination_warehouse_id, $return->id);

            if ($return->quantity <= $outstandingExclSelf) {
                continue;
            }

            $hasPending = $return->modificationRequests()
                ->where('status', 'PENDING')
                ->exists();

            if ($hasPending) {
                $this->line("[skip] {$return->transfer_number} already has a pending modification request.");
                continue;
            }

            $siteName = Warehouse::find($return->destination_warehouse_id)->name ?? "warehouse #{$return->destination_warehouse_id}";
            $proposedQty = max(0, $outstandingExclSelf);

            $modRequest = ModificationRequest::create([
                'requestable_type' => StockTransfer::class,
                'requestable_id' => $return->id,
                'requested_by' => Auth::id() ?? $return->transferred_by,
                'changes' => [
                    'quantity' => [
                        'old' => $return->quantity,
                        'new' => $proposedQty,
                    ],
                ],
                'reason' => "Automated remediation: this return of " . number_format($return->quantity) . "L exceeds the outstanding borrowed balance for {$siteName} (" . number_format(max(0, $outstandingExclSelf)) . "L excluding this record). Proposed correction reduces it to the maximum legitimate amount. Requires Operations Manager approval.",
                'status' => 'PENDING',
            ]);

            AuditLog::create([
                'admin_id' => Auth::id() ?? $return->transferred_by,
                'action' => 'REQUESTED',
                'description' => "Automated: flagged over-limit Return #{$return->transfer_number} ({$return->quantity}L vs " . number_format(max(0, $outstandingExclSelf)) . "L outstanding for {$siteName}) — Modification Request #{$modRequest->id} created for review",
            ]);

            $this->warn("[flagged] {$return->transfer_number}: {$return->quantity}L vs {$outstandingExclSelf}L outstanding for {$siteName} — Modification Request #{$modRequest->id} created (proposed: {$proposedQty}L).");
            $flagged++;
        }

        if ($flagged === 0) {
            $this->info('No over-limit Return records found. Nothing to do.');
        } else {
            $this->info("{$flagged} over-limit Return record(s) routed to the Approvals Queue for Operations Manager review.");
        }

        return self::SUCCESS;
    }
}
