<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $table = 'warehouses';

    protected $fillable = [
        'name',
    ];

    /**
     * Get tanks belonging to the warehouse.
     */
    public function tanks(): HasMany
    {
        return $this->hasMany(StorageTank::class, 'warehouse_id');
    }

    /**
     * Get active tanks belonging to the warehouse.
     */
    public function activeTanks(): HasMany
    {
        return $this->hasMany(StorageTank::class, 'warehouse_id')->where('is_active', true);
    }

    /**
     * Total stock available across active tanks in warehouse.
     */
    public function getTotalStockAvailableAttribute(): int
    {
        return $this->activeTanks->sum(fn ($tank) => $tank->stock_available);
    }

    /**
     * Total stock for delivery across active tanks in warehouse.
     */
    public function getTotalStockForDeliveryAttribute(): int
    {
        return $this->activeTanks->sum(fn ($tank) => $tank->stock_for_delivery);
    }

    /**
     * Total out across active tanks in warehouse.
     */
    public function getTotalOutAttribute(): int
    {
        return $this->activeTanks->sum(fn ($tank) => $tank->stock_out);
    }

    /**
     * Total capacity across active tanks in warehouse.
     */
    public function getTotalCapacityAttribute(): int
    {
        return $this->activeTanks->sum('max_capacity');
    }
}
