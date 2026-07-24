<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIn extends Model
{
    protected $table = 'stock_ins';

    const UPDATED_AT = null;

    protected $fillable = [
        'storage_tank_id',
        'admin_id',
        'quantity',
        'date',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'date' => 'date',
        'created_at' => 'datetime',
    ];

    public function tank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
