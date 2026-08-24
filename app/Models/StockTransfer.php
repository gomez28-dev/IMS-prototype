<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    protected $fillable = [
        'transfer_number',
        'source_tank_id',
        'destination_tank_id',
        'source_warehouse_id',
        'destination_warehouse_id',
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
}
