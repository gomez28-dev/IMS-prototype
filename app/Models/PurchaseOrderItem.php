<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    protected $fillable = [
        'purchase_order_id',
        'product',
        'quantity_ordered',
        'unit_price',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AtlAllocation::class, 'purchase_order_item_id');
    }

    /**
     * Total quantity drawn down from this item by issued ATLs.
     *
     * Most allocations carry purchase_order_item_id, but older ones only
     * recorded the parent PO. Without a fallback here a fully drawn PO would
     * still report its whole quantity as available, which is what the Allocate
     * dropdown depends on. The fallback only applies when nothing is linked,
     * so it cannot double-count.
     */
    public function getTotalDrawnAttribute(): int
    {
        $linked = (int) $this->allocations()
            ->whereHas('delivery', function ($q) {
                $q->where('status', '!=', 'Cancelled');
            })
            ->sum('quantity');

        if ($linked > 0) {
            return $linked;
        }

        return (int) $this->purchaseOrder
            ->allocations()
            ->where('product', $this->product)
            ->whereNull('purchase_order_item_id')
            ->whereHas('delivery', fn ($q) => $q->where('status', '!=', 'Cancelled'))
            ->sum('quantity');
    }

    /**
     * Remaining unallocated quantity for this product line.
     */
    public function getRemainingBalanceAttribute(): int
    {
        return max(0, $this->quantity_ordered - $this->total_drawn);
    }
}
