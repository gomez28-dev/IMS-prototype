<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        $admin = auth()->user();

        $logs = AuditLog::with('admin')
            ->when($admin->isEditor(), function ($query) use ($admin) {
                $query->where('admin_id', $admin->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return view('audit_logs.index', compact('logs'));
    }
}
