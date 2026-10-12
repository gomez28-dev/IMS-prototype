<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $table = 'stock_adjustments';

    protected $fillable = ['storage_tank_id', 'tank_compartment_id', 'quantity', 'reason', 'admin_id'];

    protected $casts = ['quantity' => 'integer'];

    public function tank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function compartment(): BelongsTo
    {
        return $this->belongsTo(TankCompartment::class, 'tank_compartment_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}