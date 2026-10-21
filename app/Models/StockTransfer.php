<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    protected $fillable = [
        'transfer_number',
        'source_tank_id',
        'destination_tank_id',
        'source_compartment_id',
        'destination_compartment_id',
        'source_warehouse_id',
        'destination_warehouse_id',
        'type', // 'transfer', 'borrow', 'return'
        'product_type', // U / D / P
        'quantity',
        'transfer_date',
        'notes',
        'transferred_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'transfer_date' => 'date',
    ];

    public function sourceTank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'source_tank_id');
    }

    public function destinationTank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'destination_tank_id');
    }

    /**
     * Truck compartment the fuel left from / went into (null for depots and older records).
     */
    public function sourceCompartment(): BelongsTo
    {
        return $this->belongsTo(TankCompartment::class, 'source_compartment_id');
    }

    public function destinationCompartment(): BelongsTo
    {
        return $this->belongsTo(TankCompartment::class, 'destination_compartment_id');
    }

    /**
     * Modification requests store "no compartment" as 0 (a null new value is skipped when a
     * request is approved), so 0 / '' are saved as NULL here.
     */
    public function setSourceCompartmentIdAttribute($value): void
    {
        $this->attributes['source_compartment_id'] = $value ? (int) $value : null;
    }

    public function setDestinationCompartmentIdAttribute($value): void
    {
        $this->attributes['destination_compartment_id'] = $value ? (int) $value : null;
    }

    public function getProductNameAttribute(): ?string
    {
        return Order::PRODUCT_TYPES[$this->product_type] ?? null;
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'transferred_by');
    }

    public function modificationRequests(): MorphMany
    {
        return $this->morphMany(ModificationRequest::class, 'requestable');
    }

    public function scopeTransfers(Builder $query): Builder
    {
        return $query->where('type', 'transfer');
    }

    public function scopeBorrows(Builder $query): Builder
    {
        return $query->where('type', 'borrow');
    }

    public function scopeReturns(Builder $query): Builder
    {
        return $query->where('type', 'return');
    }

    /**
     * Net outstanding borrowed balance for a single warehouse.
     * Outstanding = SUM(borrow WHERE source_warehouse_id = X) - SUM(return WHERE destination_warehouse_id = X)
     * May be negative if historical over-returns exist.
     */
    public static function getOutstandingBalanceFor(int $warehouseId, ?int $excludeTransferId = null): int
    {
        $borrowed = (int) self::where('type', 'borrow')->where('source_warehouse_id', $warehouseId)->sum('quantity');

        $returned = (int) self::where('type', 'return')
            ->where('destination_warehouse_id', $warehouseId)
            ->when($excludeTransferId, fn (Builder $q) => $q->where('id', '!=', $excludeTransferId))
            ->sum('quantity');

        return $borrowed - $returned;
    }

    /**
     * Compute net outstanding borrowed balance per warehouse.
     * Outstanding borrowed FROM Warehouse X:
     * SUM(quantity WHERE type='borrow' AND source_warehouse_id = X) - SUM(quantity WHERE type='return' AND destination_warehouse_id = X)
     */
    public static function getOutstandingBalances(): array
    {
        $warehouses = Warehouse::orderBy('name', 'asc')->get();
        $balances = [];

        foreach ($warehouses as $wh) {
            $borrowedFromWh = (int) self::where('type', 'borrow')->where('source_warehouse_id', $wh->id)->sum('quantity');
            $returnedToWh = (int) self::where('type', 'return')->where('destination_warehouse_id', $wh->id)->sum('quantity');
            $outstanding = max(0, $borrowedFromWh - $returnedToWh);

            $balances[$wh->id] = [
                'warehouse_id' => $wh->id,
                'warehouse_name' => $wh->name,
                'borrowed_total' => $borrowedFromWh,
                'returned_total' => $returnedToWh,
                'outstanding' => $outstanding,
                'net' => $borrowedFromWh - $returnedToWh,
            ];
        }

        return $balances;
    }
}