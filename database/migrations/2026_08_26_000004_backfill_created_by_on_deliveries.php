<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('deliveries')->whereNull('created_by')->orderBy('id')->each(function ($delivery) {
            $log = DB::table('audit_logs')
                ->where('action', 'CREATED')
                ->where('description', 'like', 'Created delivery ' . $delivery->dr_number . ' %')
                ->orderBy('created_at')
                ->first();

            if ($log && $log->admin_id) {
                DB::table('deliveries')->where('id', $delivery->id)->update(['created_by' => $log->admin_id]);
            }
        });
    }

    public function down(): void
    {
        // Leave backfilled values as-is on rollback; do not clear.
    }
};
