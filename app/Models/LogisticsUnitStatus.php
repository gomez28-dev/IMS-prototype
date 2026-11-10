<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a tanker is right now, kept current by the office Units board.
 */
class LogisticsUnitStatus extends Model
{
    public const STATUSES = [
        'garage'         => ['label' => 'At garage',                    'cls' => 'u-garage',  'icon' => 'bi-house-door',      'target' => null],
        'maintenance'    => ['label' => 'Under maintenance',            'cls' => 'u-maint',   'icon' => 'bi-tools',           'target' => null],
        'to_supplier'    => ['label' => 'In transit to supplier',       'cls' => 'u-transit', 'icon' => 'bi-truck',           'target' => 'supplier'],
        'to_client'      => ['label' => 'In transit to client',         'cls' => 'u-transit', 'icon' => 'bi-truck',           'target' => 'client'],
        'waiting_unload' => ['label' => 'At client, waiting to unload', 'cls' => 'u-wait',    'icon' => 'bi-hourglass-split', 'target' => 'client'],
        'unloading'      => ['label' => 'At client, unloading',         'cls' => 'u-unload',  'icon' => 'bi-droplet-half',    'target' => 'client'],
        'to_next_drop'   => ['label' => 'In transit to next drop',      'cls' => 'u-transit', 'icon' => 'bi-signpost-split',  'target' => 'client'],
    ];

    /** Unit status → the trip-stop status it implies. */
    public const STOP_FOR = [
        'to_client'      => 'in_transit',
        'to_next_drop'   => 'in_transit',
        'waiting_unload' => 'waiting_to_unload',
        'unloading'      => 'unloading',
    ];

    protected $fillable = [
        'storage_tank_id',
        'status',
        'target_type',
        'target_name',
        'note',
        'updated_by',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function tanker(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public static function label(string $status): string
    {
        return self::STATUSES[$status]['label'] ?? ucfirst($status);
    }

    public static function needsTarget(string $status): ?string
    {
        return self::STATUSES[$status]['target'] ?? null;
    }

    public function getLabelAttribute(): string
    {
        return self::label($this->status);
    }

    public function getIconAttribute(): string
    {
        return self::STATUSES[$this->status]['icon'] ?? 'bi-truck';
    }

    public function getCssClassAttribute(): string
    {
        return self::STATUSES[$this->status]['cls'] ?? 'u-garage';
    }
}
