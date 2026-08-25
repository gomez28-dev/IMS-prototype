<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Delivery extends Model
{
    protected $table = 'deliveries';

    protected $fillable = [
        'order_id',
        'storage_tank_id',
        'dr_number',
        'delivery_date',
        'qty_out',
        'status',
        'revised_at',
        'type',
        'remarks',
        'assigned_by',
    ];

    protected $casts = [
        'delivery_date' => 'datetime',
        'revised_at' => 'datetime',
        'qty_out' => 'integer',
        'type' => 'string',
    ];

    /**
     * Get the order that owns the delivery.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the storage tank assigned to the delivery.
     */
    public function storageTank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function modificationRequests(): MorphMany
    {
        return $this->morphMany(ModificationRequest::class, 'requestable');
    }

    /**
     * The per-tank allocation rows for this delivery (supports split assignment).
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(DeliveryAllocation::class, 'delivery_id');
    }

    /**
     * Total quantity already allocated across tanks.
     */
    public function getAllocatedQuantityAttribute(): int
    {
        return (int) $this->allocations()->sum('quantity');
    }

    /**
     * Quantity not yet allocated to any tank.
     */
    public function getRemainingToAllocateAttribute(): int
    {
        return max(0, (int) $this->qty_out - $this->allocated_quantity);
    }

    /**
     * Whether this delivery has been fully allocated across tanks.
     */
    public function getFullyAllocatedAttribute(): bool
    {
        return $this->allocated_quantity >= (int) $this->qty_out;
    }

    /**
     * Get the admin who assigned this delivery to a tank.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_by');
    }
}
