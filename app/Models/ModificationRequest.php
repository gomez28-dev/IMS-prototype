<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ModificationRequest extends Model
{
    protected $table = 'modification_requests';

    protected $fillable = [
        'requestable_type',
        'requestable_id',
        'requested_by',
        'changes',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'changes' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function requestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }

    public function getModule(): string
    {
        return match ($this->requestable_type) {
            Order::class, Delivery::class => 'Module 1',
            StockTransfer::class, StorageTank::class => 'Module 2',
            default => 'General',
        };
    }

    public function getRequestableTypeLabelAttribute(): string
    {
        return match ($this->requestable_type) {
            Order::class => 'Sales Order',
            Delivery::class => 'Delivery Record (DR)',
            StockTransfer::class => 'Stock Transfer',
            default => class_basename($this->requestable_type),
        };
    }

    public function getTargetIdentifierAttribute(): string
    {
        if (!$this->requestable) {
            return "#{$this->requestable_id} (Deleted)";
        }

        if ($this->requestable instanceof Order) {
            return "SO# {$this->requestable->so_number} ({$this->requestable->account})";
        }

        if ($this->requestable instanceof Delivery) {
            return "DR# {$this->requestable->dr_number} (SO# " . ($this->requestable->order->so_number ?? '-') . ")";
        }

        if ($this->requestable instanceof StockTransfer) {
            return "Transfer #{$this->requestable->transfer_number}";
        }

        return "#{$this->requestable_id}";
    }
}
