<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Inventory Management System') - IMS Admin</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-navy: #0f172a;
            --secondary-navy: #1e293b;
            --accent-blue: #3b82f6;
            --light-slate: #f8fafc;
            --border-color: #e2e8f0;
            --text-dark: #334155;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar-custom {
            background: linear-gradient(135deg, var(--primary-navy) 0%, var(--secondary-navy) 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .navbar-brand {
            font-size: 1.25rem;
            letter-spacing: -0.025em;
        }

        .card-custom {
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            background: #ffffff;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card-custom:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .table-custom {
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-custom th {
            background-color: var(--light-slate);
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-color);
            padding: 16px 20px;
        }

        .table-custom td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .table-custom tr:last-child td {
            border-bottom: none;
        }

        .badge-balance-zero {
            background-color: #dcfce7 !important;
            color: #166534 !important;
            font-weight: 600;
            padding: 0.5em 1em;
            font-size: 0.75rem;
        }

        .badge-balance-positive {
            background-color: #fef9c3 !important;
            color: #854d0e !important;
            font-weight: 600;
            padding: 0.5em 1em;
            font-size: 0.75rem;
        }

        .delivery-cancelled {
            background-color: var(--light-slate) !important;
            color: var(--text-muted) !important;
            text-decoration: line-through;
            opacity: 0.7;
        }

        .btn-primary-custom {
            background-color: var(--accent-blue);
            border: none;
            font-weight: 500;
            border-radius: 10px;
            padding: 0.6rem 1.4rem;
            color: #ffffff;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary-custom:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
            color: #ffffff;
        }

        .btn-secondary-custom {
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            color: var(--text-dark);
            font-weight: 500;
            border-radius: 10px;
            padding: 0.6rem 1.4rem;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-secondary-custom:hover {
            background-color: var(--light-slate);
            border-color: #cbd5e1;
            color: var(--text-dark);
        }

        /* Micro-animations */
        .btn, .card-custom, .nav-link {
            transition: all 0.2s ease-in-out;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center fw-bold text-white" href="{{ route('dashboard') }}">
                <i class="bi bi-box-seam me-2 text-primary"></i> IMS Admin
            </a>
            @auth
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item me-3">
                        <span class="nav-link text-light opacity-75">
                            <i class="bi bi-person-circle me-1"></i> {{ Auth::user()->username }}
                        </span>
                    </li>
                    <li class="nav-item">
                        <form action="{{ route('logout') }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-light btn-sm rounded-pill px-3">
                                <i class="bi bi-box-arrow-right me-1"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
            @endauth
        </div>
    </nav>

    <div class="container my-5 flex-grow-1">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm mb-4 p-3 border-0" role="alert" style="background-color: #dcfce7; color: #15803d;">
                <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if (session('danger'))
            <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm mb-4 p-3 border-0" role="alert" style="background-color: #fee2e2; color: #b91c1c;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('danger') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if (session('info'))
            <div class="alert alert-info alert-dismissible fade show rounded-4 shadow-sm mb-4 p-3 border-0" role="alert" style="background-color: #e0f2fe; color: #0369a1;">
                <i class="bi bi-info-circle-fill me-2"></i> {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @yield('content')
    </div>

    <footer class="footer mt-auto py-4 bg-white border-top text-center text-muted small">
        <div class="container">
            &copy; 2026 Admin Inventory Management System. All rights reserved.
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
