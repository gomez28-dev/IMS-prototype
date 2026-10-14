<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
 * Purchase orders that were actually raised against a supplier.
 *
 * The table also holds two kinds of placeholder row that are not purchase
 * orders:
 *
 *  - Module 2 stock requests, created by StockRequestController with no PO
 *    number, no supplier and no value. They live on Wet Stock Requests until
 *    Purchasing turns one into a real PO, at which point it gains a PO number
 *    and this scope starts picking it up.
 *  - Module 1 Sales Order drawdown rows (SO-FT / SO-BB), created by
 *    storeFuelTradePo. They track fuel issued to a client order rather than a
 *    commitment to buy, and belong to the Sales Orders page.
 *
 * A created PO therefore has a PO number and is not a Fuel Trade drawdown.
 *
 * Named createdPos rather than created to avoid colliding with Eloquent's
 * created() model event.
 *
 * @param  Builder  $query
 * @return Builder
 */
public function scopeCreatedPos(Builder $query): Builder
{
    return $query
        ->whereNotNull('po_number')
        ->where('po_number', '!=', '')
        ->where('po_type', '!=', self::TYPE_FUEL_TRADE);
}

public function isFuelTrade(): bool
    {
        return $this->po_type === 'FUEL_TRADE';
    }

    /**
     * The pipeline states that count as "the VP has approved this PO".
     *
     * Kept as one list so isVpApproved() and availableForAllocation() cannot
     * drift apart: a PO offered in an ATL dropdown is exactly a PO whose PDF
     * and allocations the system treats as cleared.
     *
     * @var list<string>
     */
    public const VP_APPROVED_STATUSES = ['CONFIRMED', 'FOR_DELIVERY', 'COMPLETED'];

    /**
     * Purchase orders an ATL can still draw volume from.
     *
     * The supervisor's rule for the PO dropdown: list the POs that still have
     * remaining quantity. "Remaining" is judged on any product, not one in
     * particular, so a PO holding only Premium is still offered.
     *
     * Only VP-approved POs are offered. Volume must not be drawn against a PO
     * that is still RECEIVED, because that PO can still be edited or sent back
     * by the VP and its quantities are not yet fixed. createdPos() also keeps
     * Fuel Trade placeholders out: those belong to a single sales order rather
     * than to the shared pool an ATL draws from.
     *
     * A collection rather than a scope because the balance is computed in PHP
     * from each PO's items and its allocations, which SQL cannot express.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function availableForAllocation(): \Illuminate\Support\Collection
    {
        return self::query()
            ->createdPos()
            ->where('status', '!=', 'Cancelled')
            ->whereIn('request_status', self::VP_APPROVED_STATUSES)
            ->with(['items.allocations', 'allocations'])
            ->get()
            ->filter(function (self $po) {
                foreach (self::PRODUCTS as $product) {
                    if ($po->getAvailableBalanceForProduct($product) > 0) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    public function isBuyBack(): bool
    {
        return $this->po_type === 'BUY_BACK';
    }

    public function isStandard(): bool
    {
        return $this->po_type === 'STANDARD_REPLENISHMENT' || empty($this->po_type);
    }

    public const TYPE_FUEL_TRADE = 'FUEL_TRADE';

    public const TYPE_BUY_BACK = 'BUY_BACK';

    public const TYPE_STANDARD_REPLENISHMENT = 'STANDARD_REPLENISHMENT';

    /**
     * There are three kinds of purchase order, and each is raised from one
     * place. The type is therefore also where the PO came from:
     *
     *   SO-FT        -> Sales Orders page
     *   SO-BUY BACK  -> Sales Orders page
     *   Doyen Stock  -> Wet Stock Requests page
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_FUEL_TRADE => 'SO-FT',
        self::TYPE_BUY_BACK => 'SO-BUY BACK',
        self::TYPE_STANDARD_REPLENISHMENT => 'Doyen Stock',
    ];

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->po_type] ?? 'Doyen Stock';
    }

    /**
     * @return array<string, string> Badge colour classes keyed by po_type.
     */
    public static function typeBadgeClasses(): array
    {
        return [
            self::TYPE_FUEL_TRADE => 'bg-warning-subtle text-dark border',
            self::TYPE_BUY_BACK => 'bg-info-subtle text-dark border',
            self::TYPE_STANDARD_REPLENISHMENT => 'bg-light text-dark border',
        ];
    }

    public function typeBadgeClass(): string
    {
        return self::typeBadgeClasses()[$this->po_type] ?? 'bg-light text-dark border';
    }

    /**
     * Has the VP signed this PO off?
     *
     * A PO is only approved once it moves past RECEIVED, which is the state
     * the Approvals queue waits on. Everything downstream of approval
     * (FOR_DELIVERY, COMPLETED) is still approved.
     */
    public function isVpApproved(): bool
    {
        return in_array($this->request_status, self::VP_APPROVED_STATUSES, true);
    }

    /**
     * Volumes already drawn from this PO, keyed by product.
     *
     * Aggregated on purchase_order_id + product rather than through the item
     * rows, because ATLs raised from a Fuel Trade sale store their allocations
     * without a purchase_order_item_id.
     *
     * @return array<string, int>
     */
    public function drawnByProduct(): array
    {
        return AtlAllocation::query()
            ->where('purchase_order_id', $this->id)
            ->whereHas('delivery', fn ($q) => $q->where('status', '!=', 'Cancelled'))
            ->selectRaw('product, SUM(quantity) AS qty')
            ->groupBy('product')
            ->pluck('qty', 'product')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * The three fuel products this module trades, in the order the reports
     * present them.
     *
     * @var array<int, string>
     */
    public const PRODUCTS = ['Diesel', 'Unleaded', 'Premium'];

    /**
     * Volumes ordered, lifted and still unlifted, split by product.
     *
     * "Lifted" is volume whose ATL has been loaded; "unlifted" is volume that
     * already has an ATL issued but has not been loaded yet. Volume that is
     * merely ordered and never ATL'd falls into neither, which is why the two
     * do not have to add up to the ordered total.
     *
     * Passing a purchase order id narrows every figure to that one PO.
     *
     * @return array{ordered: array<string, int>, lifted: array<string, int>, unlifted: array<string, int>}
     */
    public static function volumeTotals(?int $purchaseOrderId = null): array
    {
        $ordered = PurchaseOrderItem::query()
            ->selectRaw('product, SUM(quantity_ordered) AS qty')
            ->groupBy('product');

        // An ATL counts once its delivery has actually been loaded, or is
        // still outstanding. Cancelled deliveries count as neither.
        $lifted = self::allocationTotals(PurchaseOrderDelivery::LIFT_LIFTED);
        $unlifted = self::allocationTotals(PurchaseOrderDelivery::LIFT_UNLIFTED);

        if ($purchaseOrderId !== null) {
            $ordered->where('purchase_order_id', $purchaseOrderId);
            $lifted->where('atl_allocations.purchase_order_id', $purchaseOrderId);
            $unlifted->where('atl_allocations.purchase_order_id', $purchaseOrderId);
        }

        return [
            'ordered' => self::normaliseVolumes($ordered->pluck('qty', 'product')->all()),
            'lifted' => self::normaliseVolumes($lifted->pluck('qty', 'product')->all()),
            'unlifted' => self::normaliseVolumes($unlifted->pluck('qty', 'product')->all()),
        ];
    }

    /**
     * Allocation volume grouped by product for one delivery lift status.
     */
    protected static function allocationTotals(string $liftStatus): \Illuminate\Database\Eloquent\Builder
    {
        return AtlAllocation::query()
            ->join('purchase_order_deliveries', 'atl_allocations.purchase_order_delivery_id', '=', 'purchase_order_deliveries.id')
            ->where('purchase_order_deliveries.lift_status', $liftStatus)
            ->where('purchase_order_deliveries.status', '!=', 'Cancelled')
            ->selectRaw('atl_allocations.product, SUM(atl_allocations.quantity) AS qty')
            ->groupBy('atl_allocations.product');
    }

    /**
     * Give every known product an entry so the cards always show all three,
     * even where a product has no rows at all.
     *
     * @param  array<string, mixed>  $totals
     * @return array<string, int>
     */
    protected static function normaliseVolumes(array $totals): array
    {
        $out = [];
        foreach (self::PRODUCTS as $product) {
            $out[$product] = (int) ($totals[$product] ?? 0);
        }

        return $out;
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

        // A PO that carries item rows states its products exactly, so a product
        // with no line simply has nothing available. Without this guard a
        // Diesel-only PO would report its whole qty_ordered as Unleaded too,
        // and the Allocate dropdown would offer products it never bought.
        if ($this->items()->exists()) {
            return 0;
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
