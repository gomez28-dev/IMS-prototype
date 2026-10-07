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
        'product_type',
        'storage_tank_id',
        'dr_number',
        'atl_number',
        'delivery_date',
        'qty_out',
        'status',
        'revised_at',
        'type',
        'remarks',
        'assigned_by',
        'fulfilled_by',
        'fulfilled_at',
        'created_by',
    ];

    protected $casts = [
        'delivery_date' => 'datetime',
        'fulfilled_at' => 'datetime',
        'revised_at' => 'datetime',
        'qty_out' => 'integer',
        'type' => 'string',
    ];

    /**
     * Full product name for the stored code. For a multi-product DR this
     * returns a combined label such as "Unleaded + Premium".
     */
    public function getProductNameAttribute(): string
    {
        $items = $this->items;
        if ($items->isNotEmpty()) {
            return $items->map(fn ($i) => $i->product_name)->unique()->implode(' + ');
        }

        return Order::PRODUCT_TYPES[$this->product_type] ?? 'Unspecified';
    }

    /**
     * Compact compartment summary, e.g. "U 2,000 L + P 500 L".
     */
    public function getItemsSummaryAttribute(): string
    {
        $items = $this->items;
        if ($items->isEmpty()) {
            return ($this->product_type ?: '-') . ' ' . number_format((int) $this->qty_out) . ' L';
        }

        return $items->map(fn ($i) => ($i->product_type ?: '-') . ' ' . number_format($i->qty_out) . ' L')
            ->implode(' + ');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Compartment lines (product + qty) on this DR.
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class, 'delivery_id')
            ->orderBy('compartment_no')
            ->orderBy('id');
    }

    public function storageTank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function modificationRequests(): MorphMany
    {
        return $this->morphMany(ModificationRequest::class, 'requestable');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DeliveryAllocation::class, 'delivery_id');
    }

    public function getAllocatedQuantityAttribute(): int
    {
        return (int) $this->allocations()->sum('quantity');
    }

    public function getRemainingToAllocateAttribute(): int
    {
        return max(0, (int) $this->qty_out - $this->allocated_quantity);
    }

    public function getFullyAllocatedAttribute(): bool
    {
        return $this->allocated_quantity >= (int) $this->qty_out;
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_by');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'fulfilled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}