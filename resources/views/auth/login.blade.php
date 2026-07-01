@extends('layouts.app')

@section('title', 'Login')

@section('content')
<div class="row justify-content-center align-items-center mt-5">
    <div class="col-md-5 col-lg-4">
        <div class="card card-custom p-4 border-0">
            <div class="card-body">
                <div class="text-center mb-4">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock fs-2"></i>
                    </div>
                    <h4 class="fw-bold text-dark">Admin Login</h4>
                    <p class="text-muted small">Sign in to manage inventory and deliveries</p>
                </div>

                <form method="POST" action="{{ route('login') }}" novalidate>
                    @csrf
                    
                    <div class="mb-3">
                        <label for="username" class="form-label fw-medium text-secondary small">Username</label>
                        <input type="text" name="username" id="username" class="form-control form-control-lg fs-6 @error('username') is-invalid @enderror" placeholder="Enter your username" value="{{ old('username') }}" required>
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-medium text-secondary small">Password</label>
                        <input type="password" name="password" id="password" class="form-control form-control-lg fs-6 @error('password') is-invalid @enderror" placeholder="Enter your password" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary-custom btn-lg fs-6 shadow-sm">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
