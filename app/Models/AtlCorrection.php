<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction to an ATL: who made it, when, and the old and new value of
 * every field that moved.
 *
 * The fields listed here are exactly the ones a correction may change. Order
 * type, channel, product and volume are deliberately absent: they are what the
 * ATL was filed against, so they never take part in a correction.
 */
class AtlCorrection extends Model
{
    public const FIELD_LABELS = [
        'atl_number' => 'ATL Number',
        'client_atl_number' => 'Client ATL Number',
        'dr_number' => 'DR Number',
        'reference_no' => 'Reference No.',
        'so_number' => 'SO Number',
        'receiving_date' => 'Pick Up Date',
        'driver_name' => 'Driver',
        'plate_number' => 'Plate Number',
        'location' => 'Location',
        'additional_remarks' => 'Additional Remarks',
    ];

    protected $fillable = [
        'purchase_order_delivery_id',
        'admin_id',
        'field_changes',
    ];

    protected $casts = [
        'field_changes' => 'array',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDelivery::class, 'purchase_order_delivery_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * The stored diff as rows a reader can follow: field, before, after.
     *
     * Anything the label list does not know is skipped rather than printed as a
     * raw column name.
     *
     * @return array<int, array{field: string, label: string, before: string, after: string}>
     */
    public function changesForDisplay(): array
    {
        return collect($this->field_changes ?? [])
            ->filter(fn ($change, $field) => array_key_exists($field, self::FIELD_LABELS))
            ->map(fn ($change, $field) => [
                'field' => (string) $field,
                'label' => self::FIELD_LABELS[$field],
                'before' => $this->displayValue($change['from'] ?? null),
                'after' => $this->displayValue($change['to'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
    }
}
