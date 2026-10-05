<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderDelivery extends Model
{
    protected $table = 'purchase_order_deliveries';

    protected $fillable = [
        'purchase_order_id',
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
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
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
