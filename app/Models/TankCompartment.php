<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TankCompartment extends Model
{
    protected $table = 'tank_compartments';

    protected $fillable = [
        'storage_tank_id',
        'compartment_no',
        'capacity',
        'product_type',
        'opening_balance',
        'contaminated_liters',
        'contamination_note',
        'contaminated_date',
        'contaminated_by',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'compartment_no' => 'integer',
        'opening_balance' => 'integer',
        'contaminated_liters' => 'integer',
        'contaminated_date' => 'datetime',
    ];

    public function tank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class, 'tank_compartment_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'tank_compartment_id');
    }

    public function getProductNameAttribute(): ?string
    {
        return Order::PRODUCT_TYPES[$this->product_type] ?? null;
    }

    /**
     * opening_balance is stock that was already in the truck total and has been placed
     * into this compartment ("Unassigned stock already in the truck"). It counts toward
     * the compartment only, not the tank total, which already includes it.
     */
    public function getStockAvailableAttribute(): int
    {
        return max(0, (int) $this->opening_balance + (int) $this->stockIns()->sum('quantity') - (int) $this->adjustments()->sum('quantity'));
    }

    public function getRemainingCapacityAttribute(): int
    {
        return max(0, $this->capacity - $this->stock_available);
    }

    public function getSellableAvailableAttribute(): int
    {
        return max(0, $this->stock_available - $this->contaminated_liters);
    }

    /**
     * Litres that can still be allocated out of this compartment.
     *
     * Sellable volume less anything already allocated away from it. The
     * allocation screen filters on this, so it has to exist here as well as on
     * StorageTank -- without it every truck-compartment allocation silently
     * finds nothing available.
     */
    public function getEffectiveAvailableAttribute(): int
    {
        return max(0, $this->sellable_available - (int) $this->allocations()->sum('quantity'));
    }

    /**
     * Liters this delivery row has already taken out of this compartment.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(DeliveryAllocation::class, 'tank_compartment_id');
    }
}