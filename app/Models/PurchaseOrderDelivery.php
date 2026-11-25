<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseOrderDelivery extends Model
{
    public const CATEGORY_FUEL_TRADE = 'FUEL_TRADE';
    public const CATEGORY_BUY_BACK = 'BUY_BACK';
    public const CATEGORY_DOYEN_STOCKS = 'DOYEN_STOCKS';

    public const SOURCE_DOYEN_ISSUED = 'DOYEN_ISSUED';
    public const SOURCE_CLIENT_PROVIDED = 'CLIENT_PROVIDED';

    public const APPROVAL_DRAFT = 'DRAFT';
    public const APPROVAL_FOR_APPROVAL = 'FOR_APPROVAL';
    public const APPROVAL_APPROVED = 'APPROVED';
    public const APPROVAL_REJECTED = 'REJECTED';

    public const LIFT_UNLIFTED = 'UNLIFTED';
    public const LIFT_LIFTED = 'LIFTED';
    public const LIFT_CANCELLED = 'CANCELLED';

    /**
     * The fulfillment channels a stock-order ATL can be filed under.
     *
     * One list, read by the create form and by the correction page, so the two
     * can never offer or describe a channel differently.
     */
    public const DELIVERY_CHANNEL_LABELS = [
        'SUPPLIER_DOYEN_PICKUP' => 'Supplier - DITC Tanker Pick-up (Issue DITC ATL)',
        'SUPPLIER_STOCKS_DELIVERY' => 'Supplier - Direct Stocks Delivery to Depot (DR)',
        'BUY_BACK_DOYEN_PICKUP' => 'Buy Back - DITC Pick-up from Client (Issue DITC ATL)',
        'BUY_BACK_CLIENT_PICKUP' => 'Buy Back - Client Pick-up (Client / DITC ATL)',
        'BUY_BACK_STOCKS_DELIVERY' => 'Buy Back - Direct Delivery to Depot (DR)',
    ];

    protected $table = 'purchase_order_deliveries';

    protected $fillable = [
        'purchase_order_id',
        'order_id',
        'request_id',
        'storage_tank_id',
        'delivery_channel',
        'order_type',
        'atl_type',
        'dr_number',
        'atl_number',
        'client_atl_number',
        'reference_no',
        'submission_token',
        'so_number',
        'supplier_so_number',
        'supplier_dr_number',
        'scanned_doc_url',
        'product',
        'qty_to_receive',
        'receiving_date',
        'driver_name',
        'plate_number',
        'location',
        'additional_remarks',
        'status',
        'lift_status',
        'atl_category',
        'atl_source',
        'approval_status',
        'issued_at',
        'lifted_at',
        'lifted_by',
        'received_at',
        'received_by',
        'prepared_by',
        'approved_by',
        'approved_at',
        'revised_at',
    ];

    protected $casts = [
        'qty_to_receive' => 'integer',
        'receiving_date' => 'date',
        'approved_at' => 'datetime',
        'revised_at' => 'datetime',
        'issued_at' => 'datetime',
        'lifted_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * The Sales Order this ATL serves. Null for Doyen Stocks ATLs, which come
     * from a wet stock request rather than a sales order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * The Module 2 stock request this ATL serves. Set on Doyen Stocks ATLs,
     * which serve a request rather than a sales order.
     */
    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'request_id');
    }

    /**
     * Every supplier PO this ATL draws volume from.
     *
     * An ATL may span several POs, so this is the authoritative ATL -> PO
     * relationship; purchase_order_id only records the primary one.
     */
    public function sourcePurchaseOrders()
    {
        return PurchaseOrder::whereIn(
            'id',
            $this->allocations()->pluck('purchase_order_id')
        );
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'lifted_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }

    /**
     * Every correction made to this ATL, newest first.
     *
     * The VP queue reads these to show what a reopened ATL changed, so the
     * ruling is made on the change rather than on a record that silently
     * differs from the one approved.
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(AtlCorrection::class, 'purchase_order_delivery_id')->latest();
    }

    /**
     * Has this exact form submission already produced an ATL?
     *
     * The ATL forms carry a token generated once per render, so a double-click,
     * a refreshed POST or a Back-then-Save all reuse the same token. The second
     * request is answered from the record the first one wrote.
     */
    public static function alreadySubmitted(?string $token): bool
    {
        return filled($token)
            && static::query()->where('submission_token', $token)->exists();
    }

    /**
     * Create the ATL once for the given form submission.
     *
     * Returns null when this token has already been used, so the caller can
     * answer with an "already saved" notice instead of writing a duplicate.
     * The unique index on submission_token settles the race between two
     * requests that both passed alreadySubmitted().
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createOnce(array $attributes, ?string $token = null): ?self
    {
        if (blank($token)) {
            static::refuseDuplicate($attributes);

            return static::create($attributes);
        }

        if (static::alreadySubmitted($token)) {
            return null;
        }

        // The token only ties one form render to one record, so it cannot catch
        // the other way an ATL was filed twice: a fresh render of the same PO's
        // form, saved again with a new token. That is a separate save, and it is
        // refused here - the one place every ATL save path goes through - rather
        // than in each controller, so no path can file the same ATL twice.
        static::refuseDuplicate($attributes);

        try {
            return static::create(array_merge($attributes, ['submission_token' => $token]));
        } catch (QueryException $e) {
            if (static::alreadySubmitted($token)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The ATL already on file that a save with these attributes would duplicate.
     *
     * A numbered ATL is identified by its number: the same number on the same PO
     * is the same document, whatever else the second save changed. A blank ATL
     * (DITC has not issued the number yet) has no identity of its own, so its
     * shape does the identifying - the PO, whatever order or request it serves,
     * the product, the volume and the channel. Those are exactly the rows the VP
     * queue showed twice as "Not yet issued".
     *
     * Cancelled and sent-back ATLs hold no volume, so they never block the
     * corrected save that replaces them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function duplicateOf(array $attributes, ?int $ignoreId = null): ?self
    {
        if (blank($attributes['purchase_order_id'] ?? null)) {
            return null;
        }

        $query = static::query()
            ->where('purchase_order_id', $attributes['purchase_order_id'])
            ->where('status', '!=', 'Cancelled')
            ->where(function ($q) {
                $q->whereNull('approval_status')
                    ->orWhere('approval_status', '!=', self::APPROVAL_REJECTED);
            })
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));

        $atlNumber = trim((string) ($attributes['atl_number'] ?? ''));
        $clientNumber = trim((string) ($attributes['client_atl_number'] ?? ''));

        if ($atlNumber !== '' || $clientNumber !== '') {
            $query->where(function ($q) use ($atlNumber, $clientNumber) {
                if ($atlNumber !== '') {
                    $q->orWhere('atl_number', $atlNumber);
                }

                if ($clientNumber !== '') {
                    $q->orWhere('client_atl_number', $clientNumber);
                }
            });
        } else {
            $query
                ->where(function ($q) {
                    $q->whereNull('atl_number')->orWhere('atl_number', '');
                })
                ->where(function ($q) {
                    $q->whereNull('client_atl_number')->orWhere('client_atl_number', '');
                })
                ->where('order_id', $attributes['order_id'] ?? null)
                ->where('request_id', $attributes['request_id'] ?? null)
                ->where('order_type', $attributes['order_type'] ?? null)
                ->where('delivery_channel', $attributes['delivery_channel'] ?? null)
                ->where('product', $attributes['product'] ?? null)
                ->where('qty_to_receive', (int) ($attributes['qty_to_receive'] ?? 0));
        }

        return $query->orderBy('id')->first();
    }

    /**
     * Refuse a save that would file the same ATL twice.
     *
     * Thrown as a validation failure so every caller redirects back with the
     * submitted form and shows why, instead of writing a second row.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function refuseDuplicate(array $attributes): void
    {
        $duplicate = static::duplicateOf($attributes);

        if (! $duplicate) {
            return;
        }

        throw ValidationException::withMessages([
            'atl' => 'An identical ATL is already on file: '.$duplicate->duplicateDescription()
                .' If this is a separate load, record it with its own ATL number.',
        ]);
    }

    /**
     * One line describing the existing row, for the refusal message and the
     * duplicate report.
     */
    public function duplicateDescription(): string
    {
        $number = $this->atl_number ?: $this->client_atl_number;

        return 'ATL #'.$this->id
            .($number ? " ({$number})" : ' (no ATL number yet)')
            .' already covers '.number_format((int) $this->qty_to_receive).' L of '.$this->product
            .' on PO '.($this->purchaseOrder?->po_number ?? '#'.$this->purchase_order_id)
            .', saved '.($this->created_at?->format('d M Y g:i A') ?? 'earlier').'.';
    }

    /**
     * Client-provided ATLs are recorded only: no PDF, no VP approval.
     */
    public function isClientProvided(): bool
    {
        return $this->atl_source === self::SOURCE_CLIENT_PROVIDED;
    }

    /**
     * ATLs the VP has not yet ruled on: neither approved nor rejected.
     *
     * "Not ruled on" rather than "submitted", because the supervisor asked for
     * every ATL to be visible while it waits. Both the Approvals queue and the
     * sidebar badge read this, so it is defined once.
     *
     * Client-provided ATLs stay out. That is the client's own document, which
     * the system records rather than issues, so there is nothing here to approve.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePendingVpApproval(Builder $query): Builder
    {
        return $query
            ->whereIn('approval_status', [self::APPROVAL_DRAFT, self::APPROVAL_FOR_APPROVAL])
            ->where(function ($q) {
                $q->whereNull('atl_source')
                    ->orWhere('atl_source', self::SOURCE_DOYEN_ISSUED);
            });
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isLifted(): bool
    {
        return $this->lift_status === self::LIFT_LIFTED;
    }

    /**
     * Doyen Stocks ATLs are received into a depot tank after being lifted.
     */
    public function isDoyenStocks(): bool
    {
        return $this->atl_category === self::CATEGORY_DOYEN_STOCKS;
    }

    /**
     * Volumes per product, taken from the allocations rather than the summary
     * columns, so a multi-product ATL reports each product separately.
     *
     * @return array<string, int>
     */
    public function productBreakdown(): array
    {
        return $this->allocations
            ->groupBy('product')
            ->map(fn ($rows) => (int) $rows->sum('quantity'))
            ->all();
    }

    /**
     * The ATL's reference on the whiteboard: SO-FT, SO-BB or REQ.
     *
     * An ATL serves one of three things, and the reference says which:
     * a Fuel Trade or Buy Back sales order, or a Module 2 stock request.
     */
    public function refNumber(): string
    {
        if ($this->atl_category === self::CATEGORY_FUEL_TRADE || $this->order?->isFuelTrade()) {
            return 'SO-FT '.$this->sourceNumber();
        }

        if ($this->atl_category === self::CATEGORY_BUY_BACK || $this->order?->isBuyBack()) {
            return 'SO-BB '.$this->sourceNumber();
        }

        if ($this->stockRequest) {
            return $this->stockRequest->request_number ?: 'REQ-'.$this->stockRequest->id;
        }

        return 'REQ-'.$this->id;
    }

    /**
     * The bare number behind refNumber(), taken from whichever document the
     * ATL was raised against.
     */
    private function sourceNumber(): string
    {
        if ($this->order?->so_number) {
            return ltrim($this->order->so_number, 'SO-');
        }

        return (string) ($this->order_id ?? $this->id);
    }

    /**
     * The ATL's Type, as the whiteboard words it: FT, BUY BACK or
     * DOYEN - PICK UP.
     */
    public function typeLabel(): string
    {
        return match ($this->atl_category) {
            self::CATEGORY_BUY_BACK => 'BUY BACK',
            // Regular and Consumo orders are filed under FUEL_TRADE too, because
            // their ATLs draw from supplier POs and are lifted straight to the
            // client. Filing them by category alone would label every one of them
            // FT, which is wrong for an order that was never a Fuel Trade.
            self::CATEGORY_FUEL_TRADE => $this->orderCategoryLabel() ?? 'FT',
            default => $this->isDeliveredRequest() ? 'DOYEN - DELIVERY' : 'DOYEN - PICK UP',
        };
    }

    /**
     * The sales order's own category name, when this ATL is not a Fuel Trade one.
     *
     * @return string|null
     */
    private function orderCategoryLabel(): ?string
    {
        $order = $this->order;

        if (! $order) {
            return null;
        }

        if ($order->isFuelTrade()) {
            return 'FT';
        }

        if ($order->isConsumo()) {
            return 'CONSUMO';
        }

        return 'REGULAR';
    }

    /**
     * A Doyen Stocks ATL for a request that is being delivered rather than
     * collected.
     */
    private function isDeliveredRequest(): bool
    {
        return $this->stockRequest?->isDelivered() ?? false;
    }

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class, 'purchase_order_delivery_id');
    }

    public function bypassesDepotTanks(): bool
    {
        return $this->delivery_channel === 'SUPPLIER_CLIENT_PICKUP';
    }

    /**
     * Whether this ATL can still be corrected in place.
     *
     * Once it is lifted, received or cancelled, its date and driver are part of
     * that record: correcting them would rewrite history rather than fix a
     * typo. The correction routes and the lists that offer the action all read
     * this one rule.
     */
    public function canBeCorrected(): bool
    {
        return $this->status !== 'Cancelled'
            && ! in_array($this->lift_status, [self::LIFT_LIFTED, self::LIFT_CANCELLED], true)
            && ! $this->lifted_at
            && ! $this->received_at;
    }

    /**
     * The channel as the forms word it, falling back to the stored value for a
     * legacy row outside the current list.
     */
    public function deliveryChannelLabel(): string
    {
        return self::DELIVERY_CHANNEL_LABELS[$this->delivery_channel] ?? (string) $this->delivery_channel;
    }

    public function requiresAtl(): bool
    {
        return $this->atl_type === 'DITC_ATL' || ($this->order_type === 'PICK_UP' && $this->atl_type !== 'CLIENT_ATL');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AtlAllocation::class, 'purchase_order_delivery_id');
    }

    public function getAllocationsSummaryAttribute(): string
    {
        if ($this->allocations()->exists()) {
            return $this->allocations->map(function ($alloc) {
                return ($alloc->purchaseOrder?->po_number ?? 'PO') . ' (' . number_format($alloc->quantity) . ' L)';
            })->implode(', ');
        }

        return $this->purchaseOrder?->po_number ?? '—';
    }

    public function isClientAtl(): bool
    {
        return $this->atl_type === 'CLIENT_ATL' || !empty($this->client_atl_number);
    }

    public function storageTank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(StorageTank::class, 'storage_tank_id');
    }

    public function hasScannedDocs(): bool
    {
        return !empty(trim((string) $this->scanned_doc_url));
    }

    public function isTankerPickup(): bool
    {
        return in_array($this->delivery_channel, ['SUPPLIER_DOYEN_PICKUP', 'BUY_BACK_DOYEN_PICKUP']);
    }
}
