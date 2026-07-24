<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Delivery extends Model
{
    protected $table = 'deliveries';

    protected $fillable = [
        'order_id',
        'storage_tank_id',
        'dr_number',
        'delivery_date',
        'qty_out',
        'status',
        'type',
        'remarks',
        'assigned_by',
    ];

    protected $casts = [
        'delivery_date' => 'datetime',
        'qty_out' => 'integer',
        'type' => 'string',
    ];

    /**
     * Get the order that owns the delivery.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the storage tank assigned to the delivery.
     */
    public function storageTank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    /**
     * Get the admin who assigned this delivery to a tank.
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_by');
    }
}
