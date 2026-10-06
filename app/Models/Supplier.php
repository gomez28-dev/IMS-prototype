<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'company_name',
        'location',
        'address',
        'attention',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Mirror the column defaults so a freshly created model reports them
     * before it is reloaded from the database.
     */
    protected $attributes = [
        'is_active' => true,
        'location' => '',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    /**
     * Suppliers offered in the Purchase Order dropdown.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * "Company - Location", used in dropdowns and lists.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->location !== ''
            ? "{$this->company_name} - {$this->location}"
            : $this->company_name;
    }
}
