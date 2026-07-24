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
        'max_capacity',
        'is_active',
    ];

    protected $casts = [
        'max_capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class, 'storage_tank_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'storage_tank_id');
    }

    /**
     * Total stock added into this tank.
     */
    public function getTotalStockInAttribute(): int
    {
        return (int) $this->stockIns()->sum('quantity');
    }

    /**
     * Stock out (FULFILLED deliveries).
     */
    public function getStockOutAttribute(): int
    {
        return (int) $this->deliveries()
            ->where('status', 'FULFILLED')
            ->sum('qty_out');
    }

    /**
     * Stock earmarked for pending deliveries.
     */
    public function getStockForDeliveryAttribute(): int
    {
        return (int) $this->deliveries()
            ->where('status', 'PENDING')
            ->sum('qty_out');
    }

    /**
     * Stock currently available in tank (never below 0).
     */
    public function getStockAvailableAttribute(): int
    {
        return max(0, $this->total_stock_in - $this->stock_out);
    }

    /**
     * Effective available stock after accounting for pending (PENDING) deliveries.
     * This is how much more can be assigned/fulfilled without going negative.
     */
    public function getEffectiveAvailableAttribute(): int
    {
        return max(0, $this->stock_available - $this->stock_for_delivery);
    }

    /**
     * Remaining capacity for Stock IN.
     */
    public function getRemainingCapacityAttribute(): int
    {
        return max(0, $this->max_capacity - $this->stock_available);
    }
}
