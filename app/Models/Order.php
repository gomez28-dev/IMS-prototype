<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'account',
        'date',
        'qty_ordered',
        'price',
        'so_number',
        'po_number',
        'clearing_status',
        'status',
        'fulfillment_type',
        'order_category',
        'client_atl_number',
        'linked_purchase_order_id',
        'revised_at',
        'terms',
        'location',
        'created_by',
    ];

    protected $casts = [
        'date' => 'datetime',
        'revised_at' => 'datetime',
        'qty_ordered' => 'integer',
        'price' => 'decimal:2',
        'location' => 'string',
    ];

    /**
     * Get deliveries for the order.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'order_id');
    }

    public function modificationRequests(): MorphMany
    {
        return $this->morphMany(ModificationRequest::class, 'requestable');
    }

    public function linkedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'linked_purchase_order_id');
    }

    public function isFuelTrade(): bool
    {
        return $this->fulfillment_type === 'FUEL_TRADE';
    }

    public function isDepotPickup(): bool
    {
        return $this->fulfillment_type === 'DEPOT_PICKUP';
    }

    public function isBuyBack(): bool
    {
        return $this->order_category === 'BUY_BACK';
    }

    /**
     * Get UI-formatted SO identifier.
     * If Fuel Trade, automatically tags as FT-{number} (e.g. SO-0042 -> FT-0042).
     */
    public function getFormattedSoNumberAttribute(): string
    {
        if (!$this->so_number) {
            return '—';
        }

        if ($this->isFuelTrade()) {
            if (preg_match('/^SO-(.+)$/i', $this->so_number, $matches)) {
                return 'FT-' . $matches[1];
            }
            if (!str_starts_with(strtoupper($this->so_number), 'FT-')) {
                return 'FT-' . $this->so_number;
            }
            return $this->so_number;
        }

        return $this->so_number;
    }

    /**
     * Security gate: An ATL can only be issued if Fuel Trade order is Approved by Accounting and not cancelled.
     */
    public function canBeIssuedAtl(): bool
    {
        return $this->isFuelTrade()
            && $this->clearing_status === 'Approved'
            && $this->status !== 'Cancelled';
    }

    /**
     * The admin who originally created this order (nullable — historical
     * orders created before this field existed won't have one).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Scope query to orders placed in the current month OR unfulfilled carry-over orders from previous months.
     */
    public function scopeActiveOrCurrentMonth(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?: now('Asia/Manila');
        $startOfCurrentMonth = $now->copy()->startOfMonth();

        return $query->where(function (Builder $q) use ($now, $startOfCurrentMonth) {
            // 1. Orders placed in current month
            $q->where(function (Builder $inner) use ($now) {
                $inner->whereYear('date', $now->year)
                      ->whereMonth('date', $now->month);
            })
            // 2. OR unfulfilled carry-over orders from past months
            ->orWhere(function (Builder $inner) use ($startOfCurrentMonth) {
                $inner->where('date', '<', $startOfCurrentMonth)
                      ->where('status', '!=', 'Cancelled')
                      ->whereRaw('(qty_ordered - (SELECT COALESCE(SUM(qty_out), 0) FROM deliveries WHERE deliveries.order_id = orders.id AND deliveries.status = "FULFILLED") - (SELECT COALESCE(SUM(qty_out), 0) FROM deliveries WHERE deliveries.order_id = orders.id AND deliveries.status = "CANCELLED")) > 0');
            });
        });
    }

    /**
     * Check if this order originated prior to current month.
     */
    public function isCarryOver(?Carbon $now = null): bool
    {
        if (!$this->date) {
            return false;
        }
        $now = $now ?: now('Asia/Manila');
        return $this->date->lt($now->copy()->startOfMonth());
    }

    /**
     * Determine whether the order or any of its associated deliveries has been revised.
     */
    public function isRevised(): bool
    {
        if ($this->revised_at !== null) {
            return true;
        }

        if (!$this->exists) {
            return false;
        }

        if ($this->relationLoaded('deliveries')) {
            return $this->deliveries->contains(fn($d) => $d->revised_at !== null);
        }

        return $this->deliveries()->whereNotNull('revised_at')->exists();
    }

    /**
     * Get computed status string:
     * Pending / Pending - Revised / Fulfilled / Fulfilled - Revised / Cancelled / Cancelled - Revised
     */
    public function getComputedStatusAttribute(): string
    {
        $base = 'Pending';
        if ($this->status === 'Cancelled') {
            $base = 'Cancelled';
        } elseif ($this->remaining_balance <= 0) {
            $base = 'Fulfilled';
        }

        return $this->isRevised() ? "{$base} - Revised" : $base;
    }

    /**
     * Get CSS badge class matching computed status.
     */
    public function getComputedStatusBadgeClassAttribute(): string
    {
        $status = $this->computed_status;

        return match ($status) {
            'Fulfilled' => 'bg-success-subtle text-success border border-success-subtle',
            'Fulfilled - Revised' => 'bg-success-subtle text-success border border-success border-2',
            'Cancelled' => 'bg-danger-subtle text-danger border border-danger-subtle',
            'Cancelled - Revised' => 'bg-danger-subtle text-danger border border-danger border-2',
            'Pending - Revised' => 'bg-warning-subtle text-warning-emphasis border border-warning border-2',
            default => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        };
    }

    /**
     * Get total quantity delivered (only FULFILLED deliveries count).
     */
    public function getTotalQtyOutAttribute(): int
    {
        if (!$this->exists) {
            return 0;
        }

        if ($this->isFuelTrade() && $this->linkedPurchaseOrder) {
            $poDelivQty = (int) $this->linkedPurchaseOrder->deliveries()
                ->where('status', 'Completed')
                ->tap(fn ($q) => $this->scopePoDeliveriesToSo($q))
                ->sum('qty_to_receive');
            if ($poDelivQty > 0) {
                return $poDelivQty;
            }
        }

        if ($this->relationLoaded('deliveries')) {
            return (int) $this->deliveries->where('status', 'FULFILLED')->sum('qty_out');
        }

        return (int) $this->deliveries()
            ->where('status', 'FULFILLED')
            ->sum('qty_out');
    }

    /**
     * Restrict the query to the purchase-order delivery rows that belong to
     * THIS sales order, not the PO's whole completed/committed total.
     * Untagged rows count only when the PO's linked_order_id is this order.
     */
    private function scopePoDeliveriesToSo($query): void
    {
        $po = $this->linkedPurchaseOrder;
        $candidates = array_values(array_unique(array_filter([
            $this->so_number,
            $this->formatted_so_number,
        ])));

        $query->where(function ($q) use ($po, $candidates) {
            if (!empty($candidates)) {
                $q->whereIn('so_number', $candidates);
            }
            if ($po && $po->linked_order_id === $this->id) {
                $q->orWhereNull('so_number');
            }
        });
    }

    /**
     * Get total quantity committed against the order (PENDING + FULFILLED).
     */
    public function getCommittedQtyOutAttribute(): int
    {
        if (!$this->exists) {
            return 0;
        }

        if ($this->isFuelTrade() && $this->linkedPurchaseOrder) {
            $poDelivQty = (int) $this->linkedPurchaseOrder->deliveries()
                ->whereIn('status', ['Pending', 'Active', 'Completed'])
                ->tap(fn ($q) => $this->scopePoDeliveriesToSo($q))
                ->sum('qty_to_receive');
            if ($poDelivQty > 0) {
                return $poDelivQty;
            }
        }

        if ($this->relationLoaded('deliveries')) {
            return (int) $this->deliveries->whereIn('status', ['PENDING', 'FULFILLED'])->sum('qty_out');
        }

        return (int) $this->deliveries()
            ->whereIn('status', ['PENDING', 'FULFILLED'])
            ->sum('qty_out');
    }

    /**
     * Get total quantity cancelled.
     */
    public function getTotalCancelledQtyAttribute(): int
    {
        if (!$this->exists) {
            return 0;
        }

        if ($this->relationLoaded('deliveries')) {
            return (int) $this->deliveries->where('status', 'CANCELLED')->sum('qty_out');
        }

        return (int) $this->deliveries()
            ->where('status', 'CANCELLED')
            ->sum('qty_out');
    }

    /**
     * Get effective ordered quantity after cancelled DRs revise it down (computed, not stored).
     */
    public function getEffectiveQtyOrderedAttribute(): int
    {
        return (int)$this->qty_ordered - $this->total_cancelled_qty;
    }

    /**
     * Get remaining balance of ordered quantity.
     */
    public function getRemainingBalanceAttribute(): int
    {
        return $this->effective_qty_ordered - $this->total_qty_out;
    }

    /**
     * Get total order value (price per liter x effective quantity ordered).
     * Price defaults to 0.00 (column is not nullable), so this is always a
     * number — 0.00 simply means no price has been entered yet.
     */
    public function getTotalValueAttribute(): float
    {
        return round((float) $this->price * $this->effective_qty_ordered, 2);
    }
}
