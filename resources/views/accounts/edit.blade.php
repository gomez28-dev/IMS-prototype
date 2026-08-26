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
                        <label for="username" class="form-label fw-medium text-secondary small">Username</label>
                        <input type="text" name="username" id="username" class="form-control @error('username') is-invalid @enderror" value="{{ old('username', $admin->username) }}" required>
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
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
                            <optgroup label="System Administration">
                                <option value="admin" {{ old('role', $admin->role) === 'admin' ? 'selected' : '' }}>Portal Administrator (Full Access)</option>
                            </optgroup>
                            <optgroup label="Executive & Auditing">
                                <option value="vp" {{ old('role', $admin->role) === 'vp' ? 'selected' : '' }}>Vice President (Editor M1 & M2)</option>
                                <option value="hod" {{ old('role', $admin->role) === 'hod' ? 'selected' : '' }}>Head of Department (Editor M1 & M2)</option>
                                <option value="viewer" {{ old('role', $admin->role) === 'viewer' ? 'selected' : '' }}>President (Global Read-Only)</option>
                                <option value="audit" {{ old('role', $admin->role) === 'audit' ? 'selected' : '' }}>Auditor (Global Read-Only / Reports)</option>
                            </optgroup>
                            <optgroup label="Sales & Finance">
                                <option value="sales" {{ old('role', $admin->role) === 'sales' ? 'selected' : '' }}>Sales Executive (Module 1 Orders & Deliveries)</option>
                                <option value="accounting" {{ old('role', $admin->role) === 'accounting' ? 'selected' : '' }}>Accounting (Clearance & Audits)</option>
                            </optgroup>
                            <optgroup label="Operations & Wet Stock">
                                <option value="ops_admin" {{ old('role', $admin->role) === 'ops_admin' ? 'selected' : '' }}>Operations Admin (Module 2 + Delivery Fulfillment)</option>
                                <option value="ops_mgr" {{ old('role', $admin->role) === 'ops_mgr' ? 'selected' : '' }}>Operations Manager (Module 2 + Delivery Fulfillment)</option>
                                <option value="ops_wh" {{ old('role', $admin->role) === 'ops_wh' ? 'selected' : '' }}>Operations Warehouse (Stock IN, Allocations, Transfers)</option>
                                <option value="ops_log" {{ old('role', $admin->role) === 'ops_log' ? 'selected' : '' }}>Operations Logistics (Operations & Tracking)</option>
                            </optgroup>
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
