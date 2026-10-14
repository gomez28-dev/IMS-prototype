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

    /** fulfillment_type values */
    public const FULFILLMENT_DELIVERY = 'DELIVERY';

    public const FULFILLMENT_PICKUP = 'DEPOT_PICKUP';

    public const FULFILLMENT_FUEL_TRADE = 'FUEL_TRADE';

    /** order_category values */
    public const CATEGORY_CLIENT_ORDER = 'CLIENT_ORDER';

    public const CATEGORY_BUY_BACK = 'BUY_BACK';

    public const CATEGORY_CONSUMO = 'CONSUMO';

    /** How a Buy Back order brings fuel in, as chosen on the New Order form. */
    public const BUY_BACK_FULFILLMENT_PICKUP = 'PICKUP';

    public const BUY_BACK_FULFILLMENT_DELIVERY = 'DELIVERY';

    /**
     * A Buy Back order that is delivered rather than collected at a depot.
     *
     * Still recorded, because Module 1 uses it to default the fulfillment
     * picker and Module 3 uses it to label the ATL Type. It no longer decides
     * routing: every order category reaches Module 3.
     */
    public function isBuyBackDelivery(): bool
    {
        return $this->order_category === self::CATEGORY_BUY_BACK
            && $this->fulfillment_type === self::FULFILLMENT_DELIVERY;
    }

    /**
     * Scope to the orders Module 3 handles.
     *
     * Every order category reaches Module 3. Buy Back orders are no longer
     * special-cased: they raise an ATL like a regular order and their delivery
     * is assigned in Module 2's Assign Delivery.
     */
    public function scopeForModuleThree(Builder $query): Builder
    {
        return $query;
    }

    /**
     * Scope to the Buy Back orders that are delivered.
     *
     * Reported on rather than routed: kept so the M1 form and any audit can
     * still ask which Buy Back orders are delivered.
     */
    public function scopeBuyBackDelivery(Builder $query): Builder
    {
        return $query
            ->where('order_category', self::CATEGORY_BUY_BACK)
            ->where('fulfillment_type', self::FULFILLMENT_DELIVERY);
    }

    protected $fillable = [
        'account',
        'date',
        'date_needed',
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
        'date_needed' => 'date',
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
     * The product this order is for, as a readable name.
     *
     * Every order raised before the product_type column was added stores null
     * there, and the ATL Details page used to read the name straight off the
     * line, so those orders all read "Unspecified". The product is still
     * recoverable further down the chain: the PO raised against the order, and
     * an ATL already issued against it, both record a real product.
     *
     * Nothing is written back. This only answers what the order is for; the
     * order's own lines are left exactly as they were entered.
     */
    public function resolvedProduct(): string
    {
        $products = $this->resolvedProducts();

        if ($products === []) {
            return 'Unspecified';
        }

        // More than one fuel: naming one of them would overstate what is known.
        return count($products) === 1 ? $products[0] : 'Mixed';
    }

    /**
     * Every distinct product this order covers.
     *
     * An order may be for more than one fuel, and naming a single one would be
     * a claim the data does not support. So a multi-product order reads 'Mixed'
     * rather than being silently labelled after whichever product came first.
     *
     * @return array<int, string>
     */
    public function resolvedProducts(): array
    {
        $products = [];

        foreach ($this->items as $item) {
            if (filled($item->product_type)) {
                $products[] = $item->product_name;
            }
        }

        if ($products === []) {
            $fromPo = PurchaseOrder::where('linked_order_id', $this->getKey())
                ->with('items')
                ->orderBy('id')
                ->get()
                ->pluck('items')
                ->flatten()
                ->filter(fn ($item) => filled($item->product))
                ->pluck('product')
                ->all();

            if ($fromPo !== []) {
                $products = $fromPo;
            }
        }

        if ($products === []) {
            $fromAtl = PurchaseOrderDelivery::where('order_id', $this->getKey())
                ->with('allocations')
                ->orderBy('id')
                ->get()
                ->pluck('allocations')
                ->flatten()
                ->filter(fn ($allocation) => filled($allocation->product))
                ->pluck('product')
                ->all();

            if ($fromAtl !== []) {
                $products = $fromAtl;
            }
        }

        return array_values(array_unique($products));
    }

    /**
     * The same product as a U / D / P code, for the chips used across the app.
     * Null when the product cannot be identified, or is not one of the three.
     */
    public function resolvedProductCode(): ?string
    {
        $name = $this->resolvedProduct();

        $code = array_search($name, self::PRODUCT_TYPES, true);

        return $code === false ? null : $code;
    }

    /**
     * Volume already committed to this order by an ATL, per product.
     *
     * An order may carry several ATLs, so any check on how much has been drawn
     * has to be against the running total across all of them, not the most
     * recent one.
     *
     * ATLs issued before deliveries carried an order_id are picked up through
     * their PO's linked_order_id.
     *
     * @return array<string, int>
     */
    public function drawnByProduct(): array
    {
        return $this->drawnByProductWhere(fn (PurchaseOrderDelivery $atl) => true);
    }

    /**
     * Volume actually lifted off-site, per product.
     *
     * The lifted counterpart of drawnByProduct(), and subject to the same rule:
     * an ATL counts only once it has actually been marked lifted, so a figure read
     * off qty_to_receive would report fuel as gone that is still sitting on a
     * tanker.
     *
     * @return array<string, int>
     */
    public function liftedByProduct(): array
    {
        return $this->drawnByProductWhere(
            fn (PurchaseOrderDelivery $atl) => $atl->lift_status === PurchaseOrderDelivery::LIFT_LIFTED
        );
    }

    /**
     * Totals per product across this order's ATLs, filtered by $include.
     *
     * drawnByProduct() and liftedByProduct() differ only in which ATLs count, so
     * they share the query rather than repeating it - a copy that drifted is how
     * the detail page ends up claiming fuel left that was never lifted.
     *
     * @param  callable(PurchaseOrderDelivery): bool  $include
     * @return array<string, int>
     */
    private function drawnByProductWhere(callable $include): array
    {
        $atls = PurchaseOrderDelivery::where(function ($query) {
            $query->where('order_id', $this->getKey())
                ->orWhere(function ($legacy) {
                    $legacy->whereNull('order_id')
                        ->whereHas('purchaseOrder', fn ($po) => $po->where('linked_order_id', $this->getKey()));
                });
        })
            ->with('allocations')
            ->get()
            ->filter($include);

        $totals = [];

        foreach ($atls as $atl) {
            foreach ($atl->allocations as $allocation) {
                $product = $allocation->product ?: 'Unspecified';

                $totals[$product] = ($totals[$product] ?? 0) + (int) $allocation->quantity;
            }
        }

        return $totals;
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
     * Consumo order: a tag only. It is fulfilled like a regular order
     * (no ATL, no Fuel Trade rules) but its SO# is shown as CSM-###.
     */
    public function isConsumo(): bool
    {
        return $this->order_category === 'CONSUMO';
    }

    /**
     * SO tag prefix, driven by the New Order form:
     *   Fuel Trade fulfillment -> "FT"
     *   Buy Back category      -> "BB"
     *   Consumo category       -> "CSM"
     *   Regular SO             -> null (no prefix)
     * If an order were somehow several of these, Fuel Trade wins, then Buy Back.
     */
    public function getSoPrefixAttribute(): ?string
    {
        if ($this->isFuelTrade()) {
            return 'FT';
        }

        if ($this->isBuyBack()) {
            return 'BB';
        }

        if ($this->isConsumo()) {
            return 'CSM';
        }

        return null;
    }

    /**
     * Get UI-formatted SO identifier:
     *   Regular SO   -> 6203
     *   Buy Back SO  -> BB-6203
     *   Fuel Trade   -> FT-6203
     *   Consumo      -> CSM-6203
     *
     * Any existing SO-/FT-/BB-/CSM- prefix on the stored number is stripped
     * first so tags never double up (e.g. SO-0042 -> FT-0042, never FT-SO-0042).
     */
    public function getFormattedSoNumberAttribute(): string
    {
        if (!$this->so_number) {
            return '—';
        }

        $number = preg_replace('/^(SO|FT|BB|CSM)-/i', '', trim($this->so_number));

        if ($this->so_prefix === null) {
            return $number;
        }

        return $this->so_prefix.'-'.$number;
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
    public function productRequirements(?string $fallbackProduct = null): array
    {
        // An order raised before the product_type column existed has no typed
        // line at all, so its product can be recovered from the PO it raised.
        $anyTyped = $this->items->contains(fn (OrderItem $item) => filled($item->product_type));

        if ($fallbackProduct === null || $fallbackProduct === '') {
            $resolved = ! $anyTyped ? $this->resolvedProduct() : null;

            // On a partly-typed order the recovered product belongs to a typed
            // line. Booking an untyped line's volume under it would attribute
            // fuel to a product nothing on the record supports, so a partly
            // typed order keeps the neutral default instead.
            $fallbackProduct = in_array($resolved, self::PRODUCT_TYPES, true) ? $resolved : 'Diesel';
        }

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
     * The ATL category this order's ATLs are filed under.
     *
     * Buy Back is called out because its deliveries behave differently. Every
     * other category - Fuel Trade, Regular, Consumo - draws from supplier POs the
     * same way and is lifted straight to the client, so all three file under
     * FUEL_TRADE. That category is what keeps a tanker pick-up from being stocked
     * into a depot tank, and a Regular order's ATL must not do that either.
     *
     * PurchaseOrderDelivery::typeLabel() is what tells the three apart for display.
     */
    public function atlCategory(): string
    {
        return $this->isBuyBack()
            ? PurchaseOrderDelivery::CATEGORY_BUY_BACK
            : PurchaseOrderDelivery::CATEGORY_FUEL_TRADE;
    }

    /**
     * Whether an ATL may be opened for this order.
     *
     * Every order that Module 3 lists can raise one, whatever its category. The
     * supervisor drew the Create ATL button on a Regular order - it has no FT- or
     * BB- prefix - and scopeForModuleThree() lists all of them, so gating this on
     * the category offered a button that was never reachable and hid the feature
     * from most of the page.
     *
     * Clearance is deliberately not tested here. canBeIssuedAtl() is the gate that
     * matters at save time, and it is stricter.
     */
    public function canPrepareAtl(): bool
    {
        return $this->status !== 'Cancelled';
    }

    /**
     * Security gate: an ATL is only issued once Accounting has cleared the order
     * and it has not been cancelled.
     *
     * Also category-free, for the reason on canPrepareAtl().
     */
    public function canBeIssuedAtl(): bool
    {
        return $this->clearing_status === 'Approved'
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
