<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product line on a stock request.
 *
 * Qty Pending, as the M2 list reports it, is Requested minus Received.
 */
class StockRequestLine extends Model
{
    protected $fillable = [
        'stock_request_id',
        'product',
        'qty_requested',
        'qty_received',
        'unit',
    ];

    protected $casts = [
        'qty_requested' => 'integer',
        'qty_received' => 'integer',
    ];

    protected $attributes = [
        'qty_requested' => 0,
        'qty_received' => 0,
        'unit' => 'LTRS',
    ];

    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class);
    }

    /**
     * Litres still outstanding on this product line.
     */
    public function getQtyPendingAttribute(): int
    {
        return max(0, (int) $this->qty_requested - (int) $this->qty_received);
    }

    public function isFullyReceived(): bool
    {
        return $this->getQtyPendingAttribute() === 0;
    }
}