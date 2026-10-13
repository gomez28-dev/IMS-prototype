<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An official PO number, generated ahead of time so Purchasing can pick one
 * from a dropdown instead of typing it.
 *
 * Numbers are supplier-prefixed, e.g. PO-PETRON-2026-001, so a PO can be traced
 * back to its refinery by eye.
 */
class PoNumber extends Model
{
    protected $table = 'po_number_pool';

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_USED = 'USED';

    /** How many unused numbers to keep ready per supplier. */
    public const POOL_TARGET = 10;

    protected $fillable = [
        'po_number',
        'supplier_id',
        'status',
        'reserved_at',
        'used_by_purchase_order_id',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
    ];

    /**
     * The supplier code a number carries, derived from the company name:
     * "Petron Bataan Refinery" becomes PETRON, "Shell Philippines Inc" SHELL.
     */
    public static function prefixFor(?string $companyName): string
    {
        $firstWord = preg_split('/[\s,]+/', trim((string) $companyName))[0] ?? '';

        // Keep letters and digits only, so punctuation cannot leak into a number.
        $code = preg_replace('/[^A-Za-z0-9]/', '', $firstWord);

        return strtoupper(substr((string) $code, 0, 10)) ?: 'SUP';
    }

    /**
     * Make sure a supplier has unused numbers ready, topping the pool up to
     * POOL_TARGET. Runs on page load, so the dropdown is never empty and no
     * bulk generation job is needed.
     */
    public static function ensurePoolFor(Supplier $supplier, int $target = self::POOL_TARGET): void
    {
        $available = static::where('supplier_id', $supplier->id)
            ->where('status', self::STATUS_AVAILABLE)
            ->count();

        $missing = $target - $available;
        if ($missing <= 0) {
            return;
        }

        static::generateFor($supplier, $missing);
    }

    /**
     * Create $count unused numbers for a supplier, continuing its sequence.
     */
    public static function generateFor(Supplier $supplier, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $prefix = self::prefixFor($supplier->company_name);
        $year = now()->year;

        // The sequence is per prefix, not per supplier row: two supplier rows
        // sharing a company name would otherwise generate the same numbers and
        // collide on the unique index.
        $highest = static::where('po_number', 'like', "PO-{$prefix}-{$year}-%")
            ->get()
            ->map(fn (PoNumber $number) => (int) substr($number->po_number, -3))
            ->max() ?? 0;

        $now = now();

        for ($i = 1; $i <= $count; $i++) {
            static::create([
                'po_number' => sprintf('PO-%s-%d-%03d', $prefix, $year, $highest + $i),
                'supplier_id' => $supplier->id,
                'status' => self::STATUS_AVAILABLE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
 * Returns an unused number for a supplier, generating more if the pool has
 * run dry. Tests use this so they pick from the pool exactly as the UI does.
 */
public static function issueFor(Supplier $supplier): string
{
    self::ensurePoolFor($supplier);

    $available = static::where('supplier_id', $supplier->id)
        ->available()
        ->orderBy('id')
        ->first();

    if (! $available) {
        self::generateFor($supplier, 1);

        $available = static::where('supplier_id', $supplier->id)
            ->available()
            ->orderBy('id')
            ->first();
    }

    return $available?->po_number ?? '';
}

public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'used_by_purchase_order_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /**
     * Spend this number against the PO it was used for.
     */
    public function markUsed(PurchaseOrder $purchaseOrder): void
    {
        $this->update([
            'status' => self::STATUS_USED,
            'reserved_at' => now(),
            'used_by_purchase_order_id' => $purchaseOrder->id,
        ]);
    }
}