@extends('layouts.app')

@section('title', 'Login')

@section('content')
<div class="row justify-content-center align-items-center mt-5">
    <div class="col-md-5 col-lg-4 col-xl-3">
        <div class="card card-custom p-3 border-0">
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 52px; height: 52px;">
                        <i class="bi bi-shield-lock fs-4"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Welcome!</h5>
                    <p class="text-muted small">Login to manage inventory, orders and deliveries</p>
                </div>

                <form method="POST" action="{{ route('login') }}" novalidate>
                    @csrf
                    
                    <div class="mb-3">
                        <label for="username" class="form-label fw-medium text-secondary small">Username</label>
                        <input type="text" name="username" id="username" class="form-control @error('username') is-invalid @enderror" placeholder="Enter your username" value="{{ old('username') }}" required>
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-medium text-secondary small">Password</label>
                        <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" placeholder="Enter your password" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary-custom shadow-sm">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
