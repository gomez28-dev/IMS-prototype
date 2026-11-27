@extends('layouts.app')

@section('title', 'Manage Accounts')

@section('content')
<div class="mb-3">
    <a href="{{ route('dashboard') }}" class="text-decoration-none text-secondary small">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Manage Accounts</h2>
        <p class="text-muted small mb-0">Create and manage user accounts with 11-role access control.</p>
    </div>
    <a href="{{ route('accounts.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
        <i class="bi bi-person-plus me-2"></i> Create Account
    </a>
</div>

<!-- Tabs toggle -->
<div class="d-flex flex-row align-items-center gap-4 mb-4 border-bottom pb-2 ims-acct-tabs">
    <a class="fw-semibold text-decoration-none pb-1 {{ request()->routeIs('accounts.*') ? 'text-dark border-bottom border-2 border-primary' : 'text-muted' }}" href="{{ route('accounts.index') }}">
        <i class="bi bi-people me-1"></i> System Accounts
    </a>
    <a class="fw-semibold text-decoration-none pb-1 {{ request()->routeIs('clients.*') ? 'text-dark border-bottom border-2 border-primary' : 'text-muted' }}" href="{{ route('clients.index') }}">
        <i class="bi bi-building me-1"></i> Client Accounts
    </a>
</div>

<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($admins->isNotEmpty())
                        @foreach ($admins as $admin)
                        <tr>
                            <td class="ps-4 fw-semibold text-dark">{{ $admin->name }}</td>
                            <td>{{ $admin->username }}</td>
                            <td>
                                <span class="badge {{ $admin->role_badge_class }} rounded-pill px-3 py-1">{{ $admin->role_label }}</span>
                            </td>
                            <td class="text-center">
                                @if ($admin->is_active)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">Active</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="{{ route('accounts.edit', $admin->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-1" title="Edit Account">
                                        <i class="bi bi-pencil me-1"></i> Edit
                                    </a>
                                    @if ($admin->id !== Auth::id())
                                    <form method="POST" action="{{ route('accounts.toggle-active', $admin->id) }}" class="d-inline">
                                        @csrf
                                        @if ($admin->is_active)
                                            <button type="submit" class="btn btn-sm btn-outline-warning rounded-3 px-3 py-1" title="Deactivate Account" onclick="return confirm('Deactivate this account? The user will not be able to log in.');">
                                                <i class="bi bi-pause-circle me-1"></i> Deactivate
                                            </button>
                                        @else
                                            <button type="submit" class="btn btn-sm btn-outline-success rounded-3 px-3 py-1" title="Reactivate Account">
                                                <i class="bi bi-play-circle me-1"></i> Reactivate
                                            </button>
                                        @endif
                                    </form>
                                    <form method="POST" action="{{ route('accounts.destroy', $admin->id) }}" class="d-inline" onsubmit="return confirm('Delete account {{ $admin->username }}? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 px-3 py-1" title="Delete Account">
                                            <i class="bi bi-trash me-1"></i> Delete
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 d-block mb-3 text-secondary"></i>
                                No accounts found.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <!-- Mobile compact rows -->
        <div class="rec-cards rec-compact ims-compact-list">
            @if ($admins->isNotEmpty())
                @foreach ($admins as $admin)
                <div class="rec-card ims-compact-row">
                    <div class="ims-compact-main">
                        <div class="ims-compact-title">{{ $admin->name }}</div>
                        <div class="ims-compact-sub">&#64;{{ $admin->username }}</div>
                        <div class="ims-compact-badges">
                            <span class="badge {{ $admin->role_badge_class }} rounded-pill">{{ $admin->role_label }}</span>
                            @if ($admin->is_active)
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Active</span>
                            @else
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill">Inactive</span>
                            @endif
                        </div>
                    </div>
                    <div class="ims-compact-actions">
                        <a href="{{ route('accounts.edit', $admin->id) }}" class="btn btn-sm btn-outline-secondary ims-icon-btn" title="Edit Account" aria-label="Edit {{ $admin->name }}">
                            <i class="bi bi-pencil"></i>
                        </a>
                        @if ($admin->id !== Auth::id())
                        <form method="POST" action="{{ route('accounts.toggle-active', $admin->id) }}" class="d-inline">
                            @csrf
                            @if ($admin->is_active)
                                <button type="submit" class="btn btn-sm btn-outline-warning ims-icon-btn" title="Deactivate Account" aria-label="Deactivate {{ $admin->name }}" onclick="return confirm('Deactivate this account? The user will not be able to log in.');">
                                    <i class="bi bi-pause-circle"></i>
                                </button>
                            @else
                                <button type="submit" class="btn btn-sm btn-outline-success ims-icon-btn" title="Reactivate Account" aria-label="Reactivate {{ $admin->name }}">
                                    <i class="bi bi-play-circle"></i>
                                </button>
                            @endif
                        </form>
                        <form method="POST" action="{{ route('accounts.destroy', $admin->id) }}" class="d-inline" onsubmit="return confirm('Delete account {{ $admin->username }}? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger ims-icon-btn" title="Delete Account" aria-label="Delete {{ $admin->name }}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
                @endforeach
            @else
                <div class="rec-empty">
                    <i class="bi bi-people fs-1 d-block mb-3 text-secondary"></i>
                    No accounts found.
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="d-flex justify-content-center mt-4">
    {{ $admins->links() }}
</div>
@endsection
