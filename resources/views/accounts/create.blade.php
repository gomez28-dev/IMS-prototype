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
                            <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                            <option value="editor" {{ old('role') === 'editor' ? 'selected' : '' }}>Editor</option>
                            <option value="viewer" {{ old('role') === 'viewer' ? 'selected' : '' }}>Viewer</option>
                            <option value="accounting" {{ old('role') === 'accounting' ? 'selected' : '' }}>Accounting</option>
                            <option value="warehouse" {{ old('role') === 'warehouse' ? 'selected' : '' }}>Warehouse</option>
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
