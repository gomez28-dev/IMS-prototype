<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'product_type',
        'qty',
        'price',
        'amount',
    ];

    protected $casts = [
        'qty' => 'integer',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Line amount is always qty x price.
        static::saving(function (OrderItem $item) {
            $item->amount = round((float) $item->qty * (float) $item->price, 2);
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function getProductNameAttribute(): string
    {
        return Order::PRODUCT_TYPES[$this->product_type] ?? 'Unspecified';
    }
}
