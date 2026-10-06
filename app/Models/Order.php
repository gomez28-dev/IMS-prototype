<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    protected $table = 'orders';

    /**
     * Product type codes => display names.
     */
    public const PRODUCT_TYPES = [
        'U' => 'Unleaded',
        'D' => 'Diesel',
        'P' => 'Premium',
    ];

    protected $fillable = [
        'account',
        'date',
        'qty_ordered',
        'price',
        'so_number',
        'po_number',
        'clearing_status',
        'status',
        'fulfillment_type',
        'order_category',
        'client_atl_number',
        'linked_purchase_order_id',
        'revised_at',
        'terms',
        'location',
        'created_by',
        // Virtual attribute: a readable string of product lines
        // (e.g. "Unleaded (U) 5000 @ 78.00 | Diesel (D) 3000 @ 82.50").
        // Setting it replaces this order's product lines when the order is saved.
        'products',
    ];

    protected $casts = [
        'date' => 'datetime',
        'revised_at' => 'datetime',
        'qty_ordered' => 'integer',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
        'location' => 'string',
    ];

    /**
     * Product lines waiting to be written when the order is next saved.
     */
    protected ?array $pendingItems = null;

    protected static function booted(): void
    {
        static::saving(function (Order $order) {
            if ($order->pendingItems !== null) {
                // Order totals are derived from the product lines:
                // qty = sum of quantities, amount = sum of line amounts,
                // price = weighted average price per liter.
                $qty = 0;
                $amount = 0.0;
                foreach ($order->pendingItems as $line) {
                    $qty += (int) $line['qty'];
                    $amount += (int) $line['qty'] * (float) $line['price'];
                }
                $amount = round($amount, 2);

                $order->qty_ordered = $qty;
                $order->amount = $amount;
                $order->price = $qty > 0 ? round($amount / $qty, 2) : 0;
            } elseif (!$order->exists || $order->isDirty(['qty_ordered', 'price'])) {
                // Orders created/changed elsewhere without product lines.
                $order->amount = round((float) $order->qty_ordered * (float) $order->price, 2);
            }
        });

        static::saved(function (Order $order) {
            if ($order->pendingItems === null) {
                return;
            }

            $lines = $order->pendingItems;
            $order->pendingItems = null;

            DB::transaction(function () use ($order, $lines) {
                $order->items()->delete();
                foreach ($lines as $line) {
                    $order->items()->create($line);
                }
            });

            $order->unsetRelation('items');
        });
    }

    /**
     * Product lines (Unleaded / Diesel / Premium, each with its own qty and price).
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id')->orderBy('id');
    }

    /**
     * Turn product lines into a readable string:
     * "Unleaded (U) 5000 @ 78.00 | Diesel (D) 3000 @ 82.50"
     */
    public static function formatItems(iterable $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $type = is_array($item) ? ($item['product_type'] ?? null) : $item->product_type;
            $qty = is_array($item) ? $item['qty'] : $item->qty;
            $price = is_array($item) ? ($item['price'] ?? 0) : $item->price;

            $name = self::PRODUCT_TYPES[$type] ?? 'Unspecified';
            $parts[] = sprintf('%s (%s) %d @ %s', $name, $type ?: '-', (int) $qty, number_format((float) $price, 2, '.', ''));
        }

        return implode(' | ', $parts);
    }

    /**
     * Reverse of formatItems().
     */
    public static function parseItems(string $value): array
    {
        preg_match_all('/\(([UDP\-])\)\s+(\d+)\s+@\s+([\d.]+)/', $value, $matches, PREG_SET_ORDER);

        $lines = [];
        foreach ($matches as $m) {
            $lines[] = [
                'product_type' => $m[1] === '-' ? null : $m[1],
                'qty' => (int) $m[2],
                'price' => (float) $m[3],
            ];
        }

        return $lines;
    }

    public function getProductsAttribute(): string
    {
        if (!$this->exists) {
            return '';
        }

        return self::formatItems($this->items);
    }

    public function setProductsAttribute($value): void
    {
        $lines = is_string($value) ? self::parseItems($value) : [];

        if (!empty($lines)) {
            $this->pendingItems = $lines;
        }
    }

    /**
     * Get deliveries for the order.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'order_id');
    }

    public function modificationRequests(): MorphMany
    {
        return $this->morphMany(ModificationRequest::class, 'requestable');
    }

    public function linkedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'linked_purchase_order_id');
    }

    public function isFuelTrade(): bool
    {
        return $this->fulfillment_type === 'FUEL_TRADE';
    }

    public function isDepotPickup(): bool
    {
        return $this->fulfillment_type === 'DEPOT_PICKUP';
    }

    public function isBuyBack(): bool
    {
        return $this->order_category === 'BUY_BACK';
    }

    /**
     * Get UI-formatted SO identifier.
     * If Fuel Trade, automatically tags as FT-{number} (e.g. SO-0042 -> FT-0042).
     */
    public function getFormattedSoNumberAttribute(): string
    {
        if (!$this->so_number) {
            return '—';
        }

        if ($this->isFuelTrade()) {
            if (preg_match('/^SO-(.+)$/i', $this->so_number, $matches)) {
                return 'FT-' . $matches[1];
            }
            if (!str_starts_with(strtoupper($this->so_number), 'FT-')) {
                return 'FT-' . $this->so_number;
            }
            return $this->so_number;
        }

        return $this->so_number;
    }

    /**
     * Volume this order requires, keyed by full product name.
     *
     * Reads the order's product lines (which store the short U / D / P codes)
     * and maps them to the full product names used by purchase order items.
     * Orders created before multi-product lines existed have no lines, so they
     * fall back to a single requirement for the whole ordered volume.
     *
     * @return array<string, int>
     */
    public function productRequirements(string $fallbackProduct = 'Diesel'): array
    {
        $requirements = [];

        foreach ($this->items as $item) {
            $name = $item->product_type
                ? (self::PRODUCT_TYPES[$item->product_type] ?? null)
                : null;

            // A line with no recorded product type falls back to the given product.
            $name ??= $fallbackProduct;

            $requirements[$name] = ($requirements[$name] ?? 0) + (int) $item->qty;
        }

        if (empty($requirements)) {
            $requirements[$fallbackProduct] = (int) $this->qty_ordered;
        }

        return $requirements;
    }

    /**
     * Whether an ATL may be drafted for this order.
     *
     * Clearance only gates submitting for VP approval, not preparing the
     * document, so this is looser than canBeIssuedAtl().
     */
    public function canPrepareAtl(): bool
    {
        return ($this->isFuelTrade() || $this->isBuyBack()) && $this->status !== 'Cancelled';
    }

    /**
     * Security gate: An ATL can only be issued if Fuel Trade order is Approved by Accounting and not cancelled.
     */
    public function canBeIssuedAtl(): bool
    {
        return $this->isFuelTrade()
            && $this->clearing_status === 'Approved'
            && $this->status !== 'Cancelled';
    }

    /**
     * The admin who originally created this order (nullable — historical
     * orders created before this field existed won't have one).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Scope query to orders placed in the current month OR unfulfilled carry-over orders from previous months.
     */
    public function scopeActiveOrCurrentMonth(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?: now('Asia/Manila');
        $startOfCurrentMonth = $now->copy()->startOfMonth();

        return $query->where(function (Builder $q) use ($now, $startOfCurrentMonth) {
            // 1. Orders placed in current month
            $q->where(function (Builder $inner) use ($now) {
                $inner->whereYear('date', $now->year)
                      ->whereMonth('date', $now->month);
            })
            // 2. OR unfulfilled carry-over orders from past months
            ->orWhere(function (Builder $inner) use ($startOfCurrentMonth) {
                $inner->where('date', '<', $startOfCurrentMonth)
                      ->where('status', '!=', 'Cancelled')
                      ->whereRaw('(qty_ordered - (SELECT COALESCE(SUM(qty_out), 0) FROM deliveries WHERE deliveries.order_id = orders.id AND deliveries.status = "FULFILLED") - (SELECT COALESCE(SUM(qty_out), 0) FROM deliveries WHERE deliveries.order_id = orders.id AND deliveries.status = "CANCELLED")) > 0');
            });
        });
    }

    /**
     * Check if this order originated prior to current month.
     */
    public function isCarryOver(?Carbon $now = null): bool
    {
        if (!$this->date) {
            return false;
        }
        $now = $now ?: now('Asia/Manila');
        return $this->date->lt($now->copy()->startOfMonth());
    }

    /**
     * Determine whether the order or any of its associated deliveries has been revised.
     */
    public function isRevised(): bool
    {
        if ($this->revised_at !== null) {
            return true;
        }

        if (!$this->exists) {
            return false;
        }

        if ($this->relationLoaded('deliveries')) {
            return $this->deliveries->contains(fn($d) => $d->revised_at !== null);
        }

        return $this->deliveries()->whereNotNull('revised_at')->exists();
    }

    /**
     * Get computed status string:
     * Pending / Pending - Revised / Fulfilled / Fulfilled - Revised / Cancelled / Cancelled - Revised
     */
    public function getComputedStatusAttribute(): string
    {
        $base = 'Pending';
        if ($this->status === 'Cancelled') {
            $base = 'Cancelled';
        } elseif ($this->remaining_balance <= 0) {
            $base = 'Fulfilled';
        }

        return $this->isRevised() ? "{$base} - Revised" : $base;
    }

    /**
     * Get CSS badge class matching computed status.
     */
    public function getComputedStatusBadgeClassAttribute(): string
    {
        $status = $this->computed_status;

        return match ($status) {
            'Fulfilled' => 'bg-success-subtle text-success border border-success-subtle',
            'Fulfilled - Revised' => 'bg-success-subtle text-success border border-success border-2',
            'Cancelled' => 'bg-danger-subtle text-danger border border-danger-subtle',
            'Cancelled - Revised' => 'bg-danger-subtle text-danger border border-danger border-2',
            'Pending - Revised' => 'bg-warning-subtle text-warning-emphasis border border-warning border-2',
            default => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        };
    }

    /**
     * Get total quantity delivered (only FULFILLED deliveries count).
     */
    public function getTotalQtyOutAttribute(): int
    {
        if (!$this->exists) {
            return 0;
        }

        if ($this->isFuelTrade() && $this->linkedPurchaseOrder) {
            $poDelivQty = (int) $this->linkedPurchaseOrder->deliveries()
                ->where('status', 'Completed')
                ->tap(fn ($q) => $this->scopePoDeliveriesToSo($q))
                ->sum('qty_to_receive');
            if ($poDelivQty > 0) {
                return $poDelivQty;
            }
        }

        if ($this->relationLoaded('deliveries')) {
            return (int) $this->deliveries->where('status', 'FULFILLED')->sum('qty_out');
        }

        return (int) $this->deliveries()
            ->where('status', 'FULFILLED')
            ->sum('qty_out');
    }

    /**
     * Restrict the query to the purchase-order delivery rows that belong to
     * THIS sales order, not the PO's whole completed/committed total.
     * Untagged rows count only when the PO's linked_order_id is this order.
     */
    private function scopePoDeliveriesToSo($query): void
    {
        $po = $this->linkedPurchaseOrder;
        $candidates = array_values(array_unique(array_filter([
            $this->so_number,
            $this->formatted_so_number,
        ])));

        $query->where(function ($q) use ($po, $candidates) {
            if (!empty($candidates)) {
                $q->whereIn('so_number', $candidates);
            }
            if ($po && $po->linked_order_id === $this->id) {
                $q->orWhereNull('so_number');
            }
        });
    }

    /**
     * Get total quantity committed against the order (PENDING + FULFILLED).
     */
    public function getCommittedQtyOutAttribute(): int
    {
        if (!$this->exists) {
            return 0;
        }

        if ($this->isFuelTrade() && $this->linkedPurchaseOrder) {
            $poDelivQty = (int) $this->linkedPurchaseOrder->deliveries()
                ->whereIn('status', ['Pending', 'Active', 'Completed'])
                ->tap(fn ($q) => $this->scopePoDeliveriesToSo($q))
                ->sum('qty_to_receive');
            if ($poDelivQty > 0) {
                return $poDelivQty;
            }
        }

        if ($this->relationLoaded('deliveries')) {
            return (int) $this->deliveries->whereIn('status', ['PENDING', 'FULFILLED'])->sum('qty_out');
        }

        return (int) $this->deliveries()
            ->whereIn('status', ['PENDING', 'FULFILLED'])
            ->sum('qty_out');
    }

    /**
     * Get total quantity cancelled.
     */
    public function getTotalCancelledQtyAttribute(): int
    {
        if (!$this->exists) {
            return 0;
        }

        if ($this->relationLoaded('deliveries')) {
            return (int) $this->deliveries->where('status', 'CANCELLED')->sum('qty_out');
        }

        return (int) $this->deliveries()
            ->where('status', 'CANCELLED')
            ->sum('qty_out');
    }

    /**
     * Get effective ordered quantity after cancelled DRs revise it down (computed, not stored).
     */
    public function getEffectiveQtyOrderedAttribute(): int
    {
        return (int)$this->qty_ordered - $this->total_cancelled_qty;
    }

    /**
     * Get remaining balance of ordered quantity.
     */
    public function getRemainingBalanceAttribute(): int
    {
        return $this->effective_qty_ordered - $this->total_qty_out;
    }

    /**
     * Get total order value. With no cancelled quantity this is exactly the
     * stored amount (sum of all product lines); when quantity was cancelled
     * it is scaled by the weighted-average price per liter.
     */
    public function getTotalValueAttribute(): float
    {
        $qty = (int) $this->qty_ordered;
        if ($qty <= 0) {
            return 0.0;
        }

        return round(((float) $this->amount / $qty) * $this->effective_qty_ordered, 2);
    }
}
