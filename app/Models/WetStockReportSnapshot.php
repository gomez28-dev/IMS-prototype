<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WetStockReportSnapshot extends Model
{
    protected $table = 'wet_stock_report_snapshots';

    protected $fillable = [
        'title',
        'snapshot_date',
        'report_data',
        'created_by',
    ];

    protected $casts = [
        'snapshot_date' => 'datetime',
        'report_data' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
