@extends('layouts.app')

@section('title', 'Edit Account')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="mb-3">
            <a href="{{ route('accounts.index') }}" class="text-decoration-none text-secondary small">
                <i class="bi bi-arrow-left me-1"></i> Back to Accounts
            </a>
        </div>
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <h4 class="fw-bold mb-4 text-dark">
                    <i class="bi bi-person-gear text-primary me-2"></i>Edit Account
                </h4>

                <form method="POST" action="{{ route('accounts.update', $admin->id) }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label fw-medium text-secondary small">Full Name</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Juan Dela Cruz" value="{{ old('name', $admin->name) }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium text-secondary small">Username</label>
                        <input type="text" class="form-control" value="{{ $admin->username }}" disabled readonly>
                        <div class="form-text small text-muted">Username cannot be changed.</div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-medium text-secondary small">New Password</label>
                        <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" placeholder="Leave blank to keep current">
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text small text-muted">Min. 8 characters. Leave blank to keep the current password.</div>
                    </div>

                    <div class="mb-4">
                        <label for="role" class="form-label fw-medium text-secondary small">Role</label>
                        <select name="role" id="role" class="form-control form-select @error('role') is-invalid @enderror" required>
                            <option value="admin" {{ old('role', $admin->role) === 'admin' ? 'selected' : '' }}>Admin</option>
                            <option value="editor" {{ old('role', $admin->role) === 'editor' ? 'selected' : '' }}>Editor</option>
                            <option value="viewer" {{ old('role', $admin->role) === 'viewer' ? 'selected' : '' }}>Viewer</option>
                            <option value="accounting" {{ old('role', $admin->role) === 'accounting' ? 'selected' : '' }}>Accounting</option>
                        </select>
                        @error('role')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('accounts.index') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Update Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
