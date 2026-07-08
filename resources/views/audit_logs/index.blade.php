@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
<div class="mb-3">
    <a href="{{ route('dashboard') }}" class="text-decoration-none text-secondary small">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>
<div class="mb-4">
    <h2 class="fw-bold text-dark mb-1">Audit Log</h2>
    <p class="text-muted small mb-0">
        @if (Auth::user()->isAdmin())
            All actions performed by all users.
        @else
            Actions performed by your account only.
        @endif
    </p>
</div>

<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Timestamp</th>
                        @if (Auth::user()->isAdmin())
                        <th>Admin</th>
                        @endif
                        <th>Action</th>
                        <th class="pe-4">Description</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($logs->isNotEmpty())
                        @foreach ($logs as $log)
                        <tr>
                            <td class="ps-4 text-nowrap text-muted small">{{ $log->created_at ? $log->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '' }}</td>
                            @if (Auth::user()->isAdmin())
                            <td class="fw-semibold text-dark">{{ $log->admin?->name ?? 'Unknown' }}</td>
                            @endif
                            <td>
                                @if ($log->action === 'created')
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">Created</span>
                                @elseif ($log->action === 'updated')
                                    <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3 py-1">Updated</span>
                                @elseif ($log->action === 'deleted')
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">Deleted</span>
                                @else
                                    <span class="badge bg-secondary rounded-pill">{{ $log->action }}</span>
                                @endif
                            </td>
                            <td class="pe-4 text-dark">{{ $log->description }}</td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="{{ Auth::user()->isAdmin() ? 4 : 3 }}" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-text fs-1 d-block mb-3 text-secondary"></i>
                                No audit log entries yet.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="d-md-none p-3">
            @if ($logs->isNotEmpty())
                @foreach ($logs as $log)
                <div class="card border-0 bg-light mb-3 rounded-4 shadow-sm">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-muted small">{{ $log->created_at ? $log->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '' }}</span>
                            @if ($log->action === 'created')
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">Created</span>
                            @elseif ($log->action === 'updated')
                                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-3 py-1">Updated</span>
                            @elseif ($log->action === 'deleted')
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">Deleted</span>
                            @else
                                <span class="badge bg-secondary rounded-pill">{{ $log->action }}</span>
                            @endif
                        </div>
                        @if (Auth::user()->isAdmin())
                        <p class="fw-semibold text-dark mb-1">{{ $log->admin?->name ?? 'Unknown' }}</p>
                        @endif
                        <p class="mb-0 text-dark">{{ $log->description }}</p>
                    </div>
                </div>
                @endforeach
            @else
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-journal-text fs-1 d-block mb-3 text-secondary"></i>
                    No audit log entries yet.
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="d-flex justify-content-center mt-4">
    {{ $logs->links() }}
</div>
@endsection
