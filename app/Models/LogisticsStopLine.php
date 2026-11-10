<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One compartment's share of a stop. Quantities are integer litres, like
 * every other quantity column in the system; KL exists only for display.
 */
class LogisticsStopLine extends Model
{
    protected $fillable = [
        'logistics_trip_stop_id',
        'compartment_no',
        'product_type',
        'quantity',
    ];

    protected $casts = [
        'compartment_no' => 'integer',
        'quantity'       => 'integer',
    ];

    public function stop(): BelongsTo
    {
        return $this->belongsTo(LogisticsTripStop::class, 'logistics_trip_stop_id');
    }

    public function getProductNameAttribute(): string
    {
        return Order::PRODUCT_TYPES[$this->product_type] ?? 'Unspecified';
    }

    public function getQuantityKlAttribute(): float
    {
        return round($this->quantity / 1000, 2);
    }
}
