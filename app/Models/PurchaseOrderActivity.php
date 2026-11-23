<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class PurchaseOrderActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'actor_id', 'action', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_id');
    }

    /** Logging must never break the action being logged. */
    public static function record(int $purchaseOrderId, string $action, ?string $note = null): void
    {
        try {
            self::create([
                'purchase_order_id' => $purchaseOrderId,
                'actor_id' => Auth::id(),
                'action' => $action,
                'note' => $note,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}