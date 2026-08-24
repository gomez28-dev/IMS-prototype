@extends('layouts.app')

@section('title', 'Create Account')

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
                    <i class="bi bi-person-plus text-primary me-2"></i>Create Account
                </h4>

                <form method="POST" action="{{ route('accounts.store') }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label fw-medium text-secondary small">Full Name</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Juan Dela Cruz" value="{{ old('name') }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="username" class="form-label fw-medium text-secondary small">Username</label>
                        <input type="text" name="username" id="username" class="form-control @error('username') is-invalid @enderror" placeholder="e.g. juan.dela.cruz" value="{{ old('username') }}" required>
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-medium text-secondary small">Password</label>
                        <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" placeholder="Min. 8 characters" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="role" class="form-label fw-medium text-secondary small">Role</label>
                        <select name="role" id="role" class="form-control form-select @error('role') is-invalid @enderror" required>
                            <optgroup label="System Administration">
                                <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Portal Administrator (Full Access)</option>
                            </optgroup>
                            <optgroup label="Executive & Auditing">
                                <option value="vp" {{ old('role') === 'vp' ? 'selected' : '' }}>Vice President (Editor M1 & M2)</option>
                                <option value="hod" {{ old('role') === 'hod' ? 'selected' : '' }}>Head of Department (Editor M1 & M2)</option>
                                <option value="viewer" {{ old('role') === 'viewer' ? 'selected' : '' }}>President (Global Read-Only)</option>
                                <option value="audit" {{ old('role') === 'audit' ? 'selected' : '' }}>Auditor (Global Read-Only / Reports)</option>
                            </optgroup>
                            <optgroup label="Sales & Finance">
                                <option value="sales" {{ old('role') === 'sales' ? 'selected' : '' }}>Sales Executive (Module 1 Orders & Deliveries)</option>
                                <option value="accounting" {{ old('role') === 'accounting' ? 'selected' : '' }}>Accounting (Clearance & Audits)</option>
                            </optgroup>
                            <optgroup label="Operations & Wet Stock">
                                <option value="ops_admin" {{ old('role') === 'ops_admin' ? 'selected' : '' }}>Operations Admin (Module 2 + Delivery Fulfillment)</option>
                                <option value="ops_mgr" {{ old('role') === 'ops_mgr' ? 'selected' : '' }}>Operations Manager (Module 2 + Delivery Fulfillment)</option>
                                <option value="ops_wh" {{ old('role') === 'ops_wh' ? 'selected' : '' }}>Operations Warehouse (Stock IN, Allocations, Transfers)</option>
                                <option value="ops_log" {{ old('role') === 'ops_log' ? 'selected' : '' }}>Operations Logistics (Operations & Tracking)</option>
                            </optgroup>
                        </select>
                        @error('role')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('accounts.index') }}" class="btn btn-light border">Cancel</a>
                        <button type="submit" class="btn btn-primary-custom">Create Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
