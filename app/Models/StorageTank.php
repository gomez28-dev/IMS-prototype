<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageTank extends Model
{
    protected $table = 'storage_tanks';

    protected $fillable = [
        'warehouse_id',
        'name',
        'category', // 'depot' or 'tanker'
        'max_capacity',
        'is_active',
        'is_contaminated',
        'contaminated_liters',
        'contaminated_date',
        'contaminated_by',
        'remarks',
    ];

    protected $casts = [
        'max_capacity' => 'integer',
        'is_active' => 'boolean',
        'is_contaminated' => 'boolean',
        'contaminated_liters' => 'integer',
        'contaminated_date' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function contaminatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'contaminated_by');
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class, 'storage_tank_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'storage_tank_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DeliveryAllocation::class, 'storage_tank_id');
    }

    public function transfersOut(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'source_tank_id');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'destination_tank_id');
    }

    public function isDepot(): bool
    {
        return $this->category === 'depot';
    }

    public function isTanker(): bool
    {
        return $this->category === 'tanker';
    }

    /**
     * Total stock added into this tank via direct Stock IN.
     */
    public function getTotalStockInAttribute(): int
    {
        return (int) $this->stockIns()->sum('quantity');
    }

    /**
     * Total volume transferred out of this tank to other tanks/tankers.
     */
    public function getTotalTransfersOutAttribute(): int
    {
        return (int) $this->transfersOut()->sum('quantity');
    }

    /**
     * Total volume transferred into this tank from other tanks/tankers.
     */
    public function getTotalTransfersInAttribute(): int
    {
        return (int) $this->transfersIn()->sum('quantity');
    }

    /**
     * Stock out (FULFILLED deliveries) — allocated quantities for fulfilled DRs.
     */
    public function getStockOutAttribute(): int
    {
        return (int) $this->allocations()
            ->whereHas('delivery', fn ($q) => $q->where('status', 'FULFILLED'))
            ->sum('quantity');
    }

    /**
     * Stock earmarked for pending deliveries — allocated quantities for pending DRs.
     */
    public function getStockForDeliveryAttribute(): int
    {
        return (int) $this->allocations()
            ->whereHas('delivery', fn ($q) => $q->where('status', 'PENDING'))
            ->sum('quantity');
    }

    /**
     * Stock currently physically in tank (accounting for Stock IN, Transfers In, Fulfilled Stock Out, Transfers Out).
     */
    public function getStockAvailableAttribute(): int
    {
        $totalIn = $this->total_stock_in + $this->total_transfers_in;
        $totalOut = $this->stock_out + $this->total_transfers_out;
        return max(0, $totalIn - $totalOut);
    }

    /**
     * Stock available for selling (physical volume minus contaminated liters).
     */
    public function getSellableAvailableAttribute(): int
    {
        $contaminated = $this->is_contaminated ? $this->contaminated_liters : 0;
        return max(0, $this->stock_available - $contaminated);
    }

    /**
     * Effective available stock after accounting for pending (PENDING) deliveries.
     */
    public function getEffectiveAvailableAttribute(): int
    {
        return max(0, $this->sellable_available - $this->stock_for_delivery);
    }

    /**
     * Remaining capacity for Stock IN or Transfers In.
     */
    public function getRemainingCapacityAttribute(): int
    {
        return max(0, $this->max_capacity - $this->stock_available);
    }
}
