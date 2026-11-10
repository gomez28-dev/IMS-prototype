<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A driver or helper that can be assigned to a trip (Module 4).
 *
 * Deliberately small: this is a picker, not an HR record. People are
 * deactivated rather than deleted once a trip references them.
 */
class Personnel extends Model
{
    public const ROLES = [
        'driver' => 'Driver',
        'helper' => 'Helper',
    ];

    protected $table = 'personnel';

    protected $fillable = [
        'name',
        'role',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    public function tripsAsDriver(): HasMany
    {
        return $this->hasMany(LogisticsTrip::class, 'driver_id');
    }

    public function tripsAsHelper(): HasMany
    {
        return $this->hasMany(LogisticsTrip::class, 'helper_id');
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? ucfirst($this->role);
    }
}
