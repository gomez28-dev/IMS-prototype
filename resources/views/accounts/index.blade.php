@extends('layouts.app')

@section('title', 'Manage Accounts')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold text-dark mb-1">Manage Accounts</h2>
        <p class="text-muted small mb-0">Create and manage admin accounts with role-based access.</p>
    </div>
    <a href="{{ route('accounts.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
        <i class="bi bi-person-plus me-2"></i> Create Account
    </a>
</div>

<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <div class="table-responsive">
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
                                @if ($admin->role === 'admin')
                                    <span class="badge bg-primary rounded-pill px-3 py-1">Admin</span>
                                @elseif ($admin->role === 'editor')
                                    <span class="badge bg-info text-dark rounded-pill px-3 py-1">Editor</span>
                                @else
                                    <span class="badge bg-secondary rounded-pill px-3 py-1">Viewer</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($admin->is_active)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">Active</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-1">
                                    <a href="{{ route('accounts.edit', $admin->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-2 py-1" title="Edit Account">
                                        <i class="bi bi-pencil me-1"></i> Edit
                                    </a>
                                    @if ($admin->id !== Auth::id())
                                    <form method="POST" action="{{ route('accounts.toggle-active', $admin->id) }}" class="d-inline">
                                        @csrf
                                        @if ($admin->is_active)
                                            <button type="submit" class="btn btn-sm btn-outline-warning rounded-3 px-2 py-1" title="Deactivate Account" onclick="return confirm('Deactivate this account? The user will not be able to log in.');">
                                                <i class="bi bi-pause-circle me-1"></i> Deactivate
                                            </button>
                                        @else
                                            <button type="submit" class="btn btn-sm btn-outline-success rounded-3 px-2 py-1" title="Reactivate Account">
                                                <i class="bi bi-play-circle me-1"></i> Reactivate
                                            </button>
                                        @endif
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
    </div>
</div>
@endsection
