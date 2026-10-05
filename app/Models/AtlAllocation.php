<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlAllocation extends Model
{
    protected $table = 'atl_allocations';

    protected $fillable = [
        'purchase_order_delivery_id',
        'purchase_order_id',
        'purchase_order_item_id',
        'product',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDelivery::class, 'purchase_order_delivery_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
