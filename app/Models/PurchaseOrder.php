<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    protected $fillable = [
        'po_number',
        'po_type',
        'supplier_name',
        'client_id',
        'linked_order_id',
        'warehouse_id',
        'qty_ordered',
        'request_status',
        'status',
        'requested_by',
        'request_date',
        'date_needed',
        'requested_products',
        'approved_by',
        'approved_at',
        'revised_at',
        'remarks',
        'supplier_id',
        'attention',
        'terms',
        'po_date',
        'total_amount',
        'vatable_sales_amount',
        'vat_amount',
        'less_w_tax',
        'net_payable_amount',
        'prepared_by',
    ];

    protected $casts = [
        'qty_ordered' => 'integer',
        'request_date' => 'datetime',
        'date_needed' => 'date',
        'requested_products' => 'array',
        'approved_at' => 'datetime',
        'revised_at' => 'datetime',
        'po_date' => 'date',
        'total_amount' => 'decimal:2',
        'vatable_sales_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'less_w_tax' => 'decimal:2',
        'net_payable_amount' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'prepared_by');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function linkedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'linked_order_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AtlAllocation::class, 'purchase_order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PurchaseOrderDelivery::class, 'purchase_order_id');
    }

    public function modificationRequests(): MorphMany
    {
        return $this->morphMany(ModificationRequest::class, 'requestable');
    }

    public function isFuelTrade(): bool
    {
        return $this->po_type === 'FUEL_TRADE';
    }

    public function isBuyBack(): bool
    {
        return $this->po_type === 'BUY_BACK';
    }

    public function isStandard(): bool
    {
        return $this->po_type === 'STANDARD_REPLENISHMENT' || empty($this->po_type);
    }

    public function isRevised(): bool
    {
        if ($this->revised_at !== null) {
            return true;
        }

        if ($this->relationLoaded('deliveries')) {
            return $this->deliveries->contains(function ($del) {
                return $del->revised_at !== null || $del->status === 'Cancelled';
            });
        }

        return $this->deliveries()->where(function ($q) {
            $q->whereNotNull('revised_at')->orWhere('status', 'Cancelled');
        })->exists();
    }

    /**
     * Get available remaining balance for a specific product.
     */
    public function getAvailableBalanceForProduct(string $product): int
    {
        $item = $this->items()->where('product', $product)->first();
        if ($item) {
            return $item->remaining_balance;
        }

        // Fallback for legacy POs without separate item rows
        $drawn = (int) $this->allocations()
            ->where('product', $product)
            ->whereHas('delivery', fn ($q) => $q->where('status', '!=', 'Cancelled'))
            ->sum('quantity');

        return max(0, (int) $this->qty_ordered - $drawn);
    }

    public function getRemainingBalanceAttribute(): int
    {
        if ($this->relationLoaded('items') && $this->items->isNotEmpty()) {
            return (int) $this->items->sum('remaining_balance');
        } elseif ($this->exists && $this->items()->exists()) {
            return (int) $this->items->sum(fn ($i) => $i->remaining_balance);
        }

        $allocated = (int) $this->allocations()
            ->whereHas('delivery', fn ($q) => $q->where('status', '!=', 'Cancelled'))
            ->sum('quantity');

        if ($allocated > 0) {
            return max(0, (int) $this->qty_ordered - $allocated);
        }

        $received = 0;
        if ($this->relationLoaded('deliveries')) {
            $received = $this->deliveries->where('status', 'Completed')->sum('qty_to_receive');
        } elseif ($this->exists) {
            $received = $this->deliveries()->where('status', 'Completed')->sum('qty_to_receive');
        }

        return max(0, (int) $this->qty_ordered - (int) $received);
    }

    public function getComputedStatusAttribute(): string
    {
        $base = $this->status ?: 'Pending';

        if ($this->remaining_balance <= 0 && $this->qty_ordered > 0) {
            $base = 'Fulfilled';
        }

        return $this->isRevised() ? "{$base}-Revised" : $base;
    }
}
