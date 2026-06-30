<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'account',
        'date',
        'qty_ordered',
        'so_number',
    ];

    protected $casts = [
        'date' => 'datetime',
        'qty_ordered' => 'integer',
    ];

    /**
     * Get deliveries for the order.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'order_id');
    }

    /**
     * Get total quantity delivered (excluding CANCELLED status).
     */
    public function getTotalQtyOutAttribute(): int
    {
        return $this->deliveries()
            ->where('status', '!=', 'CANCELLED')
            ->sum('qty_out');
    }

    /**
     * Get remaining balance of ordered quantity.
     */
    public function getRemainingBalanceAttribute(): int
    {
        return $this->qty_ordered - $this->total_qty_out;
    }
}
