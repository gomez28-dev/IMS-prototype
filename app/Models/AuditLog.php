<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'action',
        'description',
        'details', // legacy alias — auto-mapped to 'description' on creating
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            if (empty($log->description) && !empty($log->details)) {
                $log->description = $log->details;
            }

            if (array_key_exists('details', $log->getAttributes())) {
                $log->offsetUnset('details');
            }
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
