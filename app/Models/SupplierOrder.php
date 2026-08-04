<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOrder extends Model
{
    protected $table = 'supplier_orders';

    protected $fillable = [
        'po_number',
        'warehouse_id',
        'supplier_name',
        'liters',
        'status', // UNLIFTED_PICKUP, PENDING_DELIVERY, COMPLETED
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'liters' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
