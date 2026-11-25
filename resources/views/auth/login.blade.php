@extends('layouts.app')

@section('title', 'Login')

@section('content')
<style>
    /* Login page only. This view is rendered for guests, so none of this can reach logged-in pages. */
    :root { --login-fade: 0.72; } /* 0 = photo fully visible, 1 = solid white */
    html body {
        background-color: #ffffff;
        background-image:
            linear-gradient(rgba(255, 255, 255, var(--login-fade)), rgba(255, 255, 255, var(--login-fade))),
            url('/images/login-banner.jpg');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
    }
    @media (max-width: 768px) { html body { background-attachment: scroll; } }

    /* No footer strip on the login page. */
    footer.footer { display: none !important; }

    .login-wrap { min-height: 78vh; display: flex; align-items: center; justify-content: center; }
    .login-card {
        width: 100%;
        max-width: 440px;
        border-radius: 1.25rem !important;
        box-shadow: 0 18px 48px rgba(15, 23, 42, 0.16) !important;
    }
    .login-icon {
        width: 56px; height: 56px;
        border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        background: rgba(255, 69, 0, 0.1);
        color: var(--brand-color, #ff4500);
        margin-bottom: 0.75rem;
    }
    .login-system { font-size: 0.82rem; font-weight: 600; color: #334155; letter-spacing: 0.02em; }
    .login-lead { font-size: 0.85rem; color: #64748b; line-height: 1.5; margin: 0.25rem auto 0; max-width: 300px; }
    .login-card .input-group-text { background: #f8fafc; color: #64748b; }
    .login-card .form-control { padding: 0.65rem 0.85rem; }
    .login-card .form-control:focus {
        border-color: #ff7a45;
        box-shadow: 0 0 0 0.2rem rgba(255, 69, 0, 0.15);
    }
    .login-card .btn-primary-custom { padding: 0.7rem 1rem; font-weight: 600; }
    .login-note { font-size: 0.78rem; color: #94a3b8; line-height: 1.6; }
</style>

<div class="login-wrap">
    <div class="card card-custom login-card border-0">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center mb-4">
                <div class="login-icon">
                    <i class="bi bi-shield-lock fs-4"></i>
                </div>
                <h4 class="fw-bold text-dark mb-1">Welcome!</h4>
                <div class="login-system">Inventory Management System</div>
                <p class="login-lead">Sign in with your username and password to manage inventory, orders and deliveries.</p>
            </div>

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label for="username" class="form-label fw-medium text-secondary small">Username</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" id="username" class="form-control @error('username') is-invalid @enderror" placeholder="Enter your username" value="{{ old('username') }}" autocomplete="username" autofocus required>
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label fw-medium text-secondary small">Password</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" id="togglePassword" class="btn btn-outline-secondary" aria-label="Show password" title="Show or hide password">
                            <i class="bi bi-eye"></i>
                        </button>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary-custom shadow-sm">
                        Login <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </form>

            <div class="login-note text-center mt-4">
                <i class="bi bi-lock-fill me-1"></i>Authorized personnel only.
                <span class="d-block">Forgot your password? Please contact your system administrator.</span>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('togglePassword');
    var input = document.getElementById('password');
    if (!btn || !input) return;
    btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
})();
</script>
@endsection
