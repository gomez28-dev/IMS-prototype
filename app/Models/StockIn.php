<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockIn extends Model
{
    protected $table = 'stock_ins';

    const UPDATED_AT = null;

    protected $fillable = [
        'storage_tank_id',
        'admin_id',
        'quantity',
        'date',
        'reverses_id',
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

    public function original(): BelongsTo
    {
        return $this->belongsTo(StockIn::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(StockIn::class, 'reverses_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }
}
