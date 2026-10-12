<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Module 2 stock request.
 *
 * These used to be placeholder rows in purchase_orders with no PO number,
 * which made them indistinguishable from a request that had not been numbered
 * yet. They now have their own table, and legacy_purchase_order_id keeps the
 * old row reachable during the changeover.
 */
class StockRequest extends Model
{
    public const TYPE_PICKUP = 'PICKUP';

    public const TYPE_DELIVERY = 'DELIVERY';

    public const STATUS_REQUESTED = 'REQUESTED';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_FOR_DELIVERY = 'FOR_DELIVERY';

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'request_number',
        'request_type',
        'warehouse_id',
        'requested_by',
        'request_date',
        'date_needed',
        'remarks',
        'status',
        // Set by Purchasing when they pick the request up.
        'purchasing_received_at',
        'purchasing_received_by',
        'legacy_purchase_order_id',
    ];

    protected $casts = [
        'request_date' => 'datetime',
        'date_needed' => 'date',
        'purchasing_received_at' => 'datetime',
    ];

    protected $attributes = [
        'request_type' => self::TYPE_PICKUP,
        'status' => self::STATUS_REQUESTED,
    ];

    protected static function booted(): void
    {
        // Real REQ-001 style numbers. Assigned here rather than by the database
        // so the sequence stays readable and matches how PO numbers work.
        static::creating(function (self $request) {
            if (blank($request->request_number)) {
                $request->request_number = self::nextRequestNumber();
            }
        });
    }

    /**
     * The next number in the REQ-001 sequence, padded to three digits.
     */
    public static function nextRequestNumber(): string
    {
        $highest = static::query()
            ->where('request_number', 'like', 'REQ-%')
            ->pluck('request_number')
            ->map(fn ($number) => (int) substr($number, 4))
            ->max() ?? 0;

        return 'REQ-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockRequestLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    /**
     * Whoever in Purchasing picked the request up.
     */
    public function purchasingReceiver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'purchasing_received_by');
    }

    /**
     * The placeholder purchase_orders row this request came from.
     */
    public function legacyPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'legacy_purchase_order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PurchaseOrderDelivery::class, 'request_id');
    }

    /**
     * Requests still waiting on the depot or on Purchasing.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED]);
    }

    /**
     * Total litres asked for, across every product line.
     */
    public function getQtyRequestedAttribute(): int
    {
        return (int) $this->lines->sum('qty_requested');
    }

    /**
     * Total litres actually received, across every product line.
     */
    public function getQtyReceivedAttribute(): int
    {
        return (int) $this->lines->sum('qty_received');
    }

    public function isDelivered(): bool
    {
        return $this->request_type === self::TYPE_DELIVERY;
    }

    /**
     * Purchasing has picked this request up, which the M2 list reports
     * separately from the quantities received.
     */
    public function hasBeenPickedUpByPurchasing(): bool
    {
        return $this->purchasing_received_at !== null;
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->isDelivered() ? 'Delivery' : 'Pick-up';
    }
}