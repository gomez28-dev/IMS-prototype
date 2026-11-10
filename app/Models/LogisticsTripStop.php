<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One drop (or pick-up) on a trip, with the client details snapshotted so the
 * stop survives the linked delivery being deleted.
 */
class LogisticsTripStop extends Model
{
    public const STATUSES = [
        'pending'           => 'Pending',
        'in_transit'        => 'In transit',
        'waiting_to_unload' => 'Waiting to unload',
        'unloading'         => 'Unloading',
        'completed'         => 'Completed',
    ];

    public const DONE = 'completed';

    protected $fillable = [
        'logistics_trip_id',
        'sort',
        'client_name',
        'address',
        'so_no',
        'dr_no',
        'wb_no',
        'delivery_id',
        'order_id',
        'status',
        'status_eta',
        'done_at',
    ];

    protected $casts = [
        'done_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(LogisticsTrip::class, 'logistics_trip_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LogisticsStopLine::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    public function getTotalLitresAttribute(): int
    {
        return (int) $this->lines->sum('quantity');
    }

    /**
     * The pending delivery-date change for this stop's delivery, if any.
     *
     * Logistics never writes deliveries.delivery_date; a change waits for
     * approval in Module 1's queue. The panel reads this to show the marker.
     */
    public function getPendingDateChangeAttribute(): ?ModificationRequest
    {
        if (! $this->delivery_id) {
            return null;
        }

        // The morphs('requestable') index covers the lookup; the JSON body is
        // filtered in PHP because only a handful of rows exist per delivery.
        return ModificationRequest::query()
            ->where('requestable_type', Delivery::class)
            ->where('requestable_id', $this->delivery_id)
            ->where('status', 'PENDING')
            ->get()
            ->first(fn (ModificationRequest $request) => isset($request->changes['delivery_date']['new']));
    }
}
