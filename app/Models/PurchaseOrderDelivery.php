<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderDelivery extends Model
{
    public const CATEGORY_FUEL_TRADE = 'FUEL_TRADE';
    public const CATEGORY_BUY_BACK = 'BUY_BACK';
    public const CATEGORY_DOYEN_STOCKS = 'DOYEN_STOCKS';

    public const SOURCE_DOYEN_ISSUED = 'DOYEN_ISSUED';
    public const SOURCE_CLIENT_PROVIDED = 'CLIENT_PROVIDED';

    public const APPROVAL_DRAFT = 'DRAFT';
    public const APPROVAL_FOR_APPROVAL = 'FOR_APPROVAL';
    public const APPROVAL_APPROVED = 'APPROVED';
    public const APPROVAL_REJECTED = 'REJECTED';

    public const LIFT_UNLIFTED = 'UNLIFTED';
    public const LIFT_LIFTED = 'LIFTED';
    public const LIFT_CANCELLED = 'CANCELLED';

    protected $table = 'purchase_order_deliveries';

    protected $fillable = [
        'purchase_order_id',
        'order_id',
        'storage_tank_id',
        'delivery_channel',
        'order_type',
        'atl_type',
        'dr_number',
        'atl_number',
        'client_atl_number',
        'reference_no',
        'so_number',
        'supplier_so_number',
        'supplier_dr_number',
        'scanned_doc_url',
        'product',
        'qty_to_receive',
        'receiving_date',
        'driver_name',
        'plate_number',
        'location',
        'additional_remarks',
        'status',
        'lift_status',
        'atl_category',
        'atl_source',
        'approval_status',
        'issued_at',
        'lifted_at',
        'lifted_by',
        'received_at',
        'received_by',
        'prepared_by',
        'approved_by',
        'approved_at',
        'revised_at',
    ];

    protected $casts = [
        'qty_to_receive' => 'integer',
        'receiving_date' => 'date',
        'approved_at' => 'datetime',
        'revised_at' => 'datetime',
        'issued_at' => 'datetime',
        'lifted_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * The Sales Order this ATL serves. Null for Doyen Stocks ATLs, which come
     * from a wet stock request rather than a sales order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Every supplier PO this ATL draws volume from.
     *
     * An ATL may span several POs, so this is the authoritative ATL -> PO
     * relationship; purchase_order_id only records the primary one.
     */
    public function sourcePurchaseOrders()
    {
        return PurchaseOrder::whereIn(
            'id',
            $this->allocations()->pluck('purchase_order_id')
        );
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'lifted_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }

    /**
     * Client-provided ATLs are recorded only: no PDF, no VP approval.
     */
    public function isClientProvided(): bool
    {
        return $this->atl_source === self::SOURCE_CLIENT_PROVIDED;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isLifted(): bool
    {
        return $this->lift_status === self::LIFT_LIFTED;
    }

    /**
     * Doyen Stocks ATLs are received into a depot tank after being lifted.
     */
    public function isDoyenStocks(): bool
    {
        return $this->atl_category === self::CATEGORY_DOYEN_STOCKS;
    }

    /**
     * Volumes per product, taken from the allocations rather than the summary
     * columns, so a multi-product ATL reports each product separately.
     *
     * @return array<string, int>
     */
    public function productBreakdown(): array
    {
        return $this->allocations
            ->groupBy('product')
            ->map(fn ($rows) => (int) $rows->sum('quantity'))
            ->all();
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class, 'purchase_order_delivery_id');
    }

    public function bypassesDepotTanks(): bool
    {
        return $this->delivery_channel === 'SUPPLIER_CLIENT_PICKUP';
    }

    public function requiresAtl(): bool
    {
        return $this->atl_type === 'DITC_ATL' || ($this->order_type === 'PICK_UP' && $this->atl_type !== 'CLIENT_ATL');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AtlAllocation::class, 'purchase_order_delivery_id');
    }

    public function getAllocationsSummaryAttribute(): string
    {
        if ($this->allocations()->exists()) {
            return $this->allocations->map(function ($alloc) {
                return ($alloc->purchaseOrder?->po_number ?? 'PO') . ' (' . number_format($alloc->quantity) . ' L)';
            })->implode(', ');
        }

        return $this->purchaseOrder?->po_number ?? '—';
    }

    public function isClientAtl(): bool
    {
        return $this->atl_type === 'CLIENT_ATL' || !empty($this->client_atl_number);
    }

    public function storageTank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function hasScannedDocs(): bool
    {
        return !empty(trim((string) $this->scanned_doc_url));
    }

    public function isTankerPickup(): bool
    {
        return in_array($this->delivery_channel, ['SUPPLIER_DOYEN_PICKUP', 'BUY_BACK_DOYEN_PICKUP']);
    }
}
