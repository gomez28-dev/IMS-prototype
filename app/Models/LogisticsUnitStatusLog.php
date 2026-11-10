<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of the Units board. The label is snapshotted so the log
 * still reads correctly if a status is renamed later.
 */
class LogisticsUnitStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'storage_tank_id',
        'status',
        'label',
        'target_type',
        'target_name',
        'note',
        'updated_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tanker(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }
}
