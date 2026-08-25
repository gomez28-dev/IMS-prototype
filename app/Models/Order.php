<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'account',
        'date',
        'qty_ordered',
        'so_number',
        'po_number',
        'clearing_status',
        'status',
        'revised_at',
        'terms',
        'location',
    ];

    protected $casts = [
        'date' => 'datetime',
        'revised_at' => 'datetime',
        'qty_ordered' => 'integer',
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

        if ($this->relationLoaded('deliveries')) {
            return (int) $this->deliveries->where('status', 'FULFILLED')->sum('qty_out');
        }

        return (int) $this->deliveries()
            ->where('status', 'FULFILLED')
            ->sum('qty_out');
    }

    /**
     * Get total quantity committed against the order (PENDING + FULFILLED).
     */
    public function getCommittedQtyOutAttribute(): int
    {
        if (!$this->exists) {
            return 0;
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
}
