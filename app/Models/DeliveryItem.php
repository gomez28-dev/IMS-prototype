<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryItem extends Model
{
    protected $table = 'delivery_items';

    protected $fillable = ['delivery_id', 'product_type', 'qty_out', 'compartment_no'];

    protected $casts = [
        'qty_out' => 'integer',
        'compartment_no' => 'integer',
    ];

    public function getProductNameAttribute(): string
    {
        return Order::PRODUCT_TYPES[$this->product_type] ?? 'Unspecified';
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DeliveryAllocation::class, 'delivery_item_id');
    }

    /**
     * Liters of this compartment already placed on tanks.
     */
    public function getAllocatedQuantityAttribute(): int
    {
        return (int) $this->allocations->sum('quantity');
    }

    /**
     * Liters of this compartment still needing a tank.
     */
    public function getRemainingToAllocateAttribute(): int
    {
        return max(0, (int) $this->qty_out - $this->allocated_quantity);
    }
}