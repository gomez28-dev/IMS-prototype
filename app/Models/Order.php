<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'terms',
        'location',
    ];

    protected $casts = [
        'date' => 'datetime',
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
     * Get total quantity delivered (only FULFILLED deliveries count).
     */
    public function getTotalQtyOutAttribute(): int
    {
        return $this->deliveries()
            ->where('status', 'FULFILLED')
            ->sum('qty_out');
    }

    /**
     * Get total quantity committed against the order (PENDING + FULFILLED).
     */
    public function getCommittedQtyOutAttribute(): int
    {
        return $this->deliveries()
            ->whereIn('status', ['PENDING', 'FULFILLED'])
            ->sum('qty_out');
    }

    /**
     * Get total quantity cancelled.
     */
    public function getTotalCancelledQtyAttribute(): int
    {
        return $this->deliveries()
            ->where('status', 'CANCELLED')
            ->sum('qty_out');
    }

    /**
     * Get effective ordered quantity after cancelled DRs revise it down (computed, not stored).
     */
    public function getEffectiveQtyOrderedAttribute(): int
    {
        return $this->qty_ordered - $this->total_cancelled_qty;
    }

    /**
     * Get remaining balance of ordered quantity.
     */
    public function getRemainingBalanceAttribute(): int
    {
        return $this->effective_qty_ordered - $this->total_qty_out;
    }
}
