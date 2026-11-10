<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tanker's run on one day (Module 4).
 *
 * Stops live in logistics_trip_stops; this row owns the crew, the date and
 * the lifecycle. trip_no is derived from the id after insert so it cannot
 * race and is never reused.
 */
class LogisticsTrip extends Model
{
    public const TYPES = [
        'delivery' => 'Delivery to clients',
        'pickup'   => 'Pick-up at supplier',
    ];

    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'trip_no',
        'storage_tank_id',
        'trip_type',
        'status',
        'scheduled_date',
        'supplier_name',
        'driver_id',
        'helper_id',
        'remarks',
        'started_at',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    public function tanker(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(LogisticsTripStop::class)->orderBy('sort');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'driver_id');
    }

    public function helper(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'helper_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    /** Trips may not be deleted once a stop has been completed. */
    public function hasCompletedStop(): bool
    {
        return $this->stops()->where('status', 'completed')->exists();
    }
}
