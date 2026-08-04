<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Inventory Management System') - IMS Admin</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    {{-- Early script: restore sidebar collapsed state from localStorage before first paint --}}
    <script>
        (function() {
            var c = localStorage.getItem('ims_sidebar_collapsed');
            if (c === '1') document.documentElement.setAttribute('data-sidebar-collapsed', '1');
        })();
    </script>

    <style>
        :root {
            --primary-navy: #0f172a;
            --secondary-navy: #1e293b;
            --brand-color: #FF4500;
            --brand-hover: #CC3700;
            --light-slate: #f8fafc;
            --border-color: #e2e8f0;
            --text-dark: #334155;
            --text-muted: #64748b;

            --bs-primary: #FF4500;
            --bs-primary-rgb: 255, 69, 0;
            --bs-link-color: #FF4500;
            --bs-link-hover-color: #CC3700;

            --sidebar-width: 220px;
            --sidebar-collapsed-width: 68px;
            --sidebar-current-width: var(--sidebar-width);
            --topbar-height: 54px;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f1f5f9;
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ========================================
           DESKTOP SIDEBAR (>= 992px only)
           ======================================== */
        html[data-sidebar-collapsed] {
            --sidebar-current-width: var(--sidebar-collapsed-width);
        }

        .app-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-current-width);
            height: 100vh;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            z-index: 1030;
            transition: width 0.15s ease;
            overflow: hidden;
        }

        /* Sidebar header (brand + collapse toggle) */
        .sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.08);
        }

        .sidebar-header .brand-logo {
            height: 18px;
            transition: opacity 0.15s ease;
        }

        .app-sidebar[data-collapsed="1"] .brand-logo {
            display: none;
        }

        .app-sidebar[data-collapsed="1"] .sidebar-header {
            justify-content: center;
        }

        .sidebar-collapse-btn {
            background: none;
            border: none;
            color: #64748b;
            font-size: 1rem;
            padding: 0.25rem;
            cursor: pointer;
            border-radius: 4px;
            transition: color 0.15s, background 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
        }

        .sidebar-collapse-btn:hover {
            color: #0f172a;
            background: #f1f5f9;
        }

        .app-sidebar[data-collapsed="1"] .sidebar-collapse-btn {
            margin: 0 auto;
        }

        /* Portal section label */
        .sidebar-section-label {
            padding: 0.75rem 1rem 0.4rem;
            font-size: 0.6rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
        }

        .app-sidebar[data-collapsed="1"] .sidebar-section-label {
            display: none;
        }

        /* Nav list */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 0.25rem 0;
        }

        .sidebar-nav-link {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.6rem 1rem;
            color: #334155;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 500;
            transition: background 0.12s, color 0.12s;
            border-left: 3px solid transparent;
            position: relative;
        }

        .sidebar-nav-link:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .sidebar-nav-link.active {
            background: rgba(255,69,0,0.1);
            color: var(--brand-color);
            border-left-color: var(--brand-color);
        }

        .sidebar-nav-link i {
            font-size: 1.1rem;
            width: 1.25rem;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-nav-link .nav-label {
            white-space: nowrap;
            overflow: hidden;
            transition: opacity 0.12s ease;
        }

        .app-sidebar[data-collapsed="1"] .sidebar-nav-link {
            justify-content: center;
            padding: 0.7rem 0;
            border-left-color: transparent;
            gap: 0;
        }

        .app-sidebar[data-collapsed="1"] .sidebar-nav-link.active {
            background: rgba(255,69,0,0.2);
            border-radius: 6px;
            margin: 0 8px;
        }

        .app-sidebar[data-collapsed="1"] .sidebar-nav-link .nav-label {
            display: none;
        }

        /* Switch Portal divider + link */
        .sidebar-divider {
            border-top: 1px solid rgba(0,0,0,0.08);
            margin: 0.5rem 0.75rem;
        }

        .sidebar-nav-link.switch-portal {
            font-size: 0.75rem;
            color: #64748b;
        }

        .sidebar-nav-link.switch-portal:hover {
            color: #334155;
        }

        /* User menu at bottom */
        .sidebar-user {
            border-top: 1px solid rgba(0,0,0,0.08);
            padding: 0.5rem;
            position: relative;
        }

        .sidebar-user-btn {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            width: 100%;
            background: none;
            border: none;
            color: #334155;
            padding: 0.5rem;
            border-radius: 8px;
            cursor: pointer;
            text-align: left;
            transition: background 0.12s;
        }

        .sidebar-user-btn:hover {
            background: #f1f5f9;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--brand-color);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.7rem;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }

        .user-info .user-name {
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-chevron {
            font-size: 0.7rem;
            color: #94a3b8;
            transition: transform 0.15s;
        }

        .sidebar-user-btn[aria-expanded="true"] .user-chevron {
            transform: rotate(180deg);
        }

        .app-sidebar[data-collapsed="1"] .user-info,
        .app-sidebar[data-collapsed="1"] .user-chevron {
            display: none;
        }

        .app-sidebar[data-collapsed="1"] .sidebar-user-btn {
            justify-content: center;
            padding: 0.5rem 0;
        }

        /* User dropdown */
        .user-dropdown {
            display: none;
            position: absolute;
            bottom: 100%;
            left: 0.5rem;
            right: 0.5rem;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.4rem;
            margin-bottom: 0.25rem;
            box-shadow: 0 -4px 16px rgba(0,0,0,0.1);
            z-index: 10;
        }

        .user-dropdown.open {
            display: block;
        }

        .app-sidebar[data-collapsed="1"] .user-dropdown {
            left: calc(var(--sidebar-collapsed-width) + 4px);
            right: auto;
            bottom: 0;
            width: 180px;
        }

        .user-dropdown .dropdown-item-custom {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            color: #334155;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 6px;
            background: none;
            border: none;
            width: 100%;
            cursor: pointer;
            transition: background 0.1s;
        }

        .user-dropdown .dropdown-item-custom:hover {
            background: #f1f5f9;
        }

        /* Role badge styles for sidebar */
        .sidebar-user .badge-role-admin {
            background-color: #FFEDD5 !important;
            color: #C2410C !important;
            border-color: rgba(194, 65, 12, 0.3) !important;
        }
        .sidebar-user .badge-role-editor {
            background-color: #E0F2FE !important;
            color: #075985 !important;
            border-color: rgba(7, 89, 133, 0.3) !important;
        }
        .sidebar-user .badge-role-viewer {
            background-color: #F1F5F9 !important;
            color: #475569 !important;
            border-color: rgba(71, 85, 105, 0.3) !important;
        }
        .sidebar-user .badge-role-warehouse {
            background-color: #FEF3C7 !important;
            color: #92400E !important;
            border-color: rgba(146, 64, 14, 0.3) !important;
        }
        .sidebar-user .badge-role-accounting {
            background-color: #F3E8FF !important;
            color: #6B21A8 !important;
            border-color: rgba(107, 33, 168, 0.3) !important;
        }

        /* ========================================
           SLIM TOP BAR
           ======================================== */
        .app-topbar {
            height: var(--topbar-height);
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .app-topbar .topbar-title {
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .topbar-portal-label {
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--text-muted);
            background: var(--light-slate);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 0.15rem 0.5rem;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* ========================================
           MAIN CONTENT WRAPPER (desktop offset)
           ======================================== */
        @media (min-width: 992px) {
            .app-content-wrapper {
                margin-left: var(--sidebar-width);
                transition: margin-left 0.15s ease;
                display: flex;
                flex-direction: column;
                min-height: 100vh;
            }

            html[data-sidebar-collapsed="1"] .app-content-wrapper,
            .app-content-wrapper.sidebar-is-collapsed {
                margin-left: var(--sidebar-collapsed-width);
            }
        }

        /* ========================================
           EXISTING COMPONENT STYLES (preserved)
           ======================================== */
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

        .badge-type-pickup {
            background-color: #e8f0fe !important;
            color: #1967d2 !important;
            border: 1px solid #c5d9f9 !important;
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
            background-color: var(--brand-color);
            border: none;
            font-weight: 500;
            border-radius: 10px;
            padding: 0.6rem 1.4rem;
            color: #ffffff;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary-custom:hover {
            background-color: var(--brand-hover);
            transform: translateY(-1px);
            color: #ffffff;
        }

        .btn-outline-primary {
            --bs-btn-color: var(--brand-color);
            --bs-btn-border-color: var(--brand-color);
            --bs-btn-hover-bg: var(--brand-color);
            --bs-btn-hover-border-color: var(--brand-color);
            --bs-btn-active-bg: var(--brand-color);
            --bs-btn-active-border-color: var(--brand-color);
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

        .badge-role-admin {
            background-color: #FFEDD5 !important;
            color: #C2410C !important;
            border: 1px solid rgba(194, 65, 12, 0.3) !important;
            font-weight: 600;
            font-size: 0.65rem;
            letter-spacing: 0.04em;
        }

        .badge-role-editor {
            background-color: #E0F2FE !important;
            color: #075985 !important;
            border: 1px solid rgba(7, 89, 133, 0.3) !important;
            font-weight: 600;
            font-size: 0.65rem;
            letter-spacing: 0.04em;
        }

        .badge-role-viewer {
            background-color: #F1F5F9 !important;
            color: #475569 !important;
            border: 1px solid rgba(71, 85, 105, 0.3) !important;
            font-weight: 600;
            font-size: 0.65rem;
            letter-spacing: 0.04em;
        }

        .badge-role-warehouse {
            background-color: #FEF3C7 !important;
            color: #92400E !important;
            border: 1px solid rgba(146, 64, 14, 0.3) !important;
            font-weight: 600;
            font-size: 0.65rem;
            letter-spacing: 0.04em;
        }

        .badge-role-accounting {
            background-color: #F3E8FF !important;
            color: #6B21A8 !important;
            border: 1px solid rgba(107, 33, 168, 0.3) !important;
            font-weight: 600;
            font-size: 0.65rem;
            letter-spacing: 0.04em;
        }

        .badge-type-big-tanker {
            background-color: #ffedd5 !important;
            color: #c2410c !important;
            border: 1px solid #fed7aa !important;
            font-weight: 600;
            padding: 0.5em 1em;
            font-size: 0.75rem;
        }

        .badge-type-small-tanker {
            background-color: #e0e7ff !important;
            color: #3730a3 !important;
            border: 1px solid #c7d2fe !important;
            font-weight: 600;
            padding: 0.5em 1em;
            font-size: 0.75rem;
        }

        /* Consistent action button sizing */
        .table-custom .btn-sm {
            padding: 0.35rem 0.7rem;
            min-height: 32px;
            min-width: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        .card .btn-sm {
            padding: 0.45rem 0.9rem;
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }

        /* Micro-animations */
        .btn, .card-custom {
            transition: all 0.2s ease-in-out;
        }

        /* ========================================
           MOBILE DRAWER (< 992px, unchanged)
           ======================================== */
        .sidebar-drawer {
            position: fixed;
            top: 0;
            left: -300px;
            width: 280px;
            height: 100vh;
            background: #ffffff;
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
            z-index: 1050;
            transition: left 0.3s ease-in-out;
            overflow-y: auto;
        }

        .sidebar-drawer.open {
            left: 0;
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.4);
            z-index: 1040;
            display: none;
        }

        .sidebar-overlay.open {
            display: block;
        }

        .sidebar-drawer .nav-link {
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-dark);
            font-weight: 500;
        }

        .sidebar-drawer .nav-link:hover {
            background-color: var(--light-slate);
            color: var(--brand-color);
        }

        .sidebar-drawer .nav-link i {
            width: 1.5rem;
        }

        /* Mobile top bar adjustments */
        .mobile-topbar {
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .hamburger-btn {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            color: var(--text-dark);
            padding: 0.25rem 0.5rem;
            cursor: pointer;
        }
    </style>
</head>
<body>

    {{-- ============ MOBILE OVERLAY ============ --}}
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    @auth
    @php
        $__unassignedDeliveriesCount = request()->is('wetstock*')
            ? \App\Models\Delivery::whereNull('storage_tank_id')->where('status', '!=', 'CANCELLED')->count()
            : 0;
    @endphp
    {{-- ============ MOBILE SIDEBAR DRAWER (< 992px, preserved) ============ --}}
    <div class="sidebar-drawer d-lg-none" id="sidebarDrawer">
        <div class="d-flex flex-column h-100">
            <div class="p-3 border-bottom">
                <h5 class="fw-bold mb-0 text-dark">Menu</h5>
            </div>
            <nav class="flex-grow-1">
                @if (request()->is('wetstock*'))
                    <a class="nav-link d-flex align-items-center" href="{{ route('wetstock.dashboard') }}">
                        <i class="bi bi-speedometer2 me-2"></i> Dashboard
                    </a>
                    <a class="nav-link d-flex align-items-center" href="{{ route('wetstock.stock-in.index') }}">
                        <i class="bi bi-fuel-pump me-2"></i> Stock IN Log
                    </a>
                    <a class="nav-link d-flex align-items-center" href="{{ route('wetstock.supplier-orders.index') }}">
                        <i class="bi bi-box-arrow-in-down me-2"></i> Incoming Stock
                    </a>
                    <a class="nav-link d-flex align-items-center" href="{{ route('wetstock.deliveries.index') }}">
                        <i class="bi bi-truck me-2"></i> Assign Deliveries
                        @if ($__unassignedDeliveriesCount > 0)
                            <span class="badge bg-warning text-dark rounded-pill ms-1">{{ $__unassignedDeliveriesCount }}</span>
                        @endif
                    </a>
                    <a class="nav-link d-flex align-items-center" href="{{ route('wetstock.reports.index') }}">
                        <i class="bi bi-file-earmark-bar-graph me-2"></i> Wet Stock Report
                    </a>
                @elseif (!request()->routeIs('portal'))
                    <a class="nav-link d-flex align-items-center" href="{{ route('dashboard') }}">
                        <i class="bi bi-speedometer2 me-2"></i> Dashboard
                    </a>
                    <a class="nav-link d-flex align-items-center" href="{{ route('reports.index') }}">
                        <i class="bi bi-bar-chart-line me-2"></i> Reports
                    </a>
                    @if (Auth::user()->isAdmin())
                    <a class="nav-link d-flex align-items-center" href="{{ route('accounts.index') }}">
                        <i class="bi bi-people me-2"></i> Manage Accounts
                    </a>
                    @endif
                    @if (Auth::user()->isEditor())
                    <a class="nav-link d-flex align-items-center" href="{{ route('clients.index') }}">
                        <i class="bi bi-building me-2"></i> Manage Clients
                    </a>
                    @endif
                    @if (Auth::user()->isAdmin() || Auth::user()->isAccounting())
                    <a class="nav-link d-flex align-items-center" href="{{ route('audit-logs') }}">
                        <i class="bi bi-journal-text me-2"></i> Audit Log
                    </a>
                    @endif
                @endif

                @if (!request()->routeIs('portal'))
                    <a class="nav-link d-flex align-items-center text-primary" href="{{ route('portal') }}">
                        <i class="bi bi-grid me-2"></i> Switch Portal
                    </a>
                @endif
            </nav>
            <div class="mt-auto border-top p-3">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-person-circle fs-5 me-2 text-muted"></i>
                    <div>
                        <div class="fw-semibold small text-dark">{{ Auth::user()->name }}</div>
                        @if (Auth::user()->isAdmin())
                            <span class="badge badge-role-admin rounded-pill px-2 py-0" style="font-size: 0.6rem;">Admin</span>
                        @elseif (Auth::user()->isEditor())
                            <span class="badge badge-role-editor rounded-pill px-2 py-0" style="font-size: 0.6rem;">Editor</span>
                        @elseif (Auth::user()->isAccounting())
                            <span class="badge badge-role-accounting rounded-pill px-2 py-0" style="font-size: 0.6rem;">Accounting</span>
                        @elseif (Auth::user()->isWarehouse())
                            <span class="badge badge-role-warehouse rounded-pill px-2 py-0" style="font-size: 0.6rem;">Warehouse</span>
                        @else
                            <span class="badge badge-role-viewer rounded-pill px-2 py-0" style="font-size: 0.6rem;">Viewer</span>
                        @endif
                    </div>
                </div>
                <form action="{{ route('logout') }}" method="POST" class="w-100">
                    @csrf
                    <button type="submit" class="btn btn-outline-dark btn-sm rounded-pill w-100">
                        <i class="bi bi-box-arrow-right me-1"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>

    @if (!request()->routeIs('portal'))
    {{-- ============ DESKTOP SIDEBAR (>= 992px) ============ --}}
    <aside id="appSidebar" class="app-sidebar d-none d-lg-flex" data-collapsed="0">
        {{-- Header: brand + collapse toggle --}}
        <div class="sidebar-header">
            <a href="{{ route('portal') }}">
                <img src="{{ asset('images/logo_ims.png') }}" alt="IMS" class="brand-logo">
            </a>
            <button class="sidebar-collapse-btn" id="sidebarToggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
                <i class="bi bi-chevron-left" id="collapseIcon"></i>
            </button>
        </div>

        {{-- Section label --}}
        <div class="sidebar-section-label">
            @if (request()->is('wetstock*'))
                Wet Stock
            @else
                Sales Documentation
            @endif
        </div>

        {{-- Nav links --}}
        <nav class="sidebar-nav">
            @if (request()->is('wetstock*'))
                <a href="{{ route('wetstock.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('wetstock.dashboard') ? 'active' : '' }}" title="Dashboard">
                    <i class="bi bi-speedometer2"></i>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="{{ route('wetstock.stock-in.index') }}" class="sidebar-nav-link {{ request()->routeIs('wetstock.stock-in.*') ? 'active' : '' }}" title="Stock IN Log">
                    <i class="bi bi-fuel-pump"></i>
                    <span class="nav-label">Stock IN Log</span>
                </a>
                <a href="{{ route('wetstock.supplier-orders.index') }}" class="sidebar-nav-link {{ request()->routeIs('wetstock.supplier-orders.*') ? 'active' : '' }}" title="Incoming Stock">
                    <i class="bi bi-box-arrow-in-down"></i>
                    <span class="nav-label">Incoming Stock</span>
                </a>
                <a href="{{ route('wetstock.deliveries.index') }}" class="sidebar-nav-link {{ request()->routeIs('wetstock.deliveries.*') ? 'active' : '' }}" title="Assign Deliveries">
                    <i class="bi bi-truck"></i>
                    <span class="nav-label">Assign Deliveries</span>
                    @if ($__unassignedDeliveriesCount > 0)
                        <span class="badge bg-warning text-dark rounded-pill ms-auto">{{ $__unassignedDeliveriesCount }}</span>
                    @endif
                </a>
                <a href="{{ route('wetstock.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('wetstock.reports.*') ? 'active' : '' }}" title="Wet Stock Report">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span class="nav-label">Wet Stock Report</span>
                </a>
            @else
                <a href="{{ route('dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" title="Dashboard">
                    <i class="bi bi-speedometer2"></i>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="{{ route('reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" title="Reports">
                    <i class="bi bi-bar-chart-line"></i>
                    <span class="nav-label">Reports</span>
                </a>
                @if (Auth::user()->isAdmin())
                <a href="{{ route('accounts.index') }}" class="sidebar-nav-link {{ request()->routeIs('accounts.*') ? 'active' : '' }}" title="Manage Accounts">
                    <i class="bi bi-people"></i>
                    <span class="nav-label">Manage Accounts</span>
                </a>
                @endif
                @if (Auth::user()->isEditor())
                <a href="{{ route('clients.index') }}" class="sidebar-nav-link {{ request()->routeIs('clients.*') ? 'active' : '' }}" title="Manage Clients">
                    <i class="bi bi-building"></i>
                    <span class="nav-label">Manage Clients</span>
                </a>
                @endif
                @if (Auth::user()->isAdmin() || Auth::user()->isAccounting())
                <a href="{{ route('audit-logs') }}" class="sidebar-nav-link {{ request()->routeIs('audit-logs') ? 'active' : '' }}" title="Audit Log">
                    <i class="bi bi-journal-text"></i>
                    <span class="nav-label">Audit Log</span>
                </a>
                @endif
            @endif

            <div class="sidebar-divider"></div>
            <a href="{{ route('portal') }}" class="sidebar-nav-link switch-portal" title="Switch Portal">
                <i class="bi bi-grid"></i>
                <span class="nav-label">Switch Portal</span>
            </a>
        </nav>

        {{-- User menu (bottom) --}}
        <div class="sidebar-user">
            <div class="user-dropdown" id="userDropdown">
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="dropdown-item-custom">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </form>
            </div>
            @php
                $nameParts = explode(' ', Auth::user()->name);
                $initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[count($nameParts)-1]) && count($nameParts) > 1 ? substr($nameParts[count($nameParts)-1], 0, 1) : ''));
            @endphp
            <button class="sidebar-user-btn" id="userMenuBtn" type="button" aria-haspopup="true" aria-expanded="false">
                <div class="user-avatar">{{ $initials }}</div>
                <div class="user-info">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    @if (Auth::user()->isAdmin())
                        <span class="badge badge-role-admin rounded-pill px-2 py-0" style="font-size: 0.55rem;">Admin</span>
                    @elseif (Auth::user()->isEditor())
                        <span class="badge badge-role-editor rounded-pill px-2 py-0" style="font-size: 0.55rem;">Editor</span>
                    @elseif (Auth::user()->isAccounting())
                        <span class="badge badge-role-accounting rounded-pill px-2 py-0" style="font-size: 0.55rem;">Accounting</span>
                    @elseif (Auth::user()->isWarehouse())
                        <span class="badge badge-role-warehouse rounded-pill px-2 py-0" style="font-size: 0.55rem;">Warehouse</span>
                    @else
                        <span class="badge badge-role-viewer rounded-pill px-2 py-0" style="font-size: 0.55rem;">Viewer</span>
                    @endif
                </div>
                <i class="bi bi-chevron-up user-chevron"></i>
            </button>
        </div>
    </aside>
    @endif
    @endauth

    {{-- ============ MAIN CONTENT WRAPPER ============ --}}
    @auth
        @if (request()->routeIs('portal'))
            {{-- Portal page: no sidebar, simple centered layout --}}
            <div class="mobile-topbar d-lg-none">
                <a href="{{ route('portal') }}">
                    <img src="{{ asset('images/logo_ims.png') }}" alt="IMS" style="height: 24px;">
                </a>
                <form action="{{ route('logout') }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-dark btn-sm rounded-pill px-3" style="font-size: 0.75rem;">
                        <i class="bi bi-box-arrow-right me-1"></i> Logout
                    </button>
                </form>
            </div>
            {{-- Desktop: simple topbar with logo + logout for portal --}}
            <div class="app-topbar d-none d-lg-flex">
                <div class="topbar-title">
                    <img src="{{ asset('images/logo_ims.png') }}" alt="IMS" style="height: 26px;">
                    <span>Portal Selector</span>
                </div>
                <div class="topbar-actions">
                    <span class="me-3 text-muted small">
                        <i class="bi bi-person-circle me-1"></i>{{ Auth::user()->name }}
                    </span>
                    <form action="{{ route('logout') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-dark btn-sm rounded-pill px-3" style="font-size: 0.75rem;">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </button>
                    </form>
                </div>
            </div>

            <div class="flex-grow-1">
                <div class="container my-5">
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm mb-4 p-3 border-0" role="alert" style="background-color: #dcfce7; color: #15803d;">
                            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
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
            </div>
        @else
            {{-- Non-portal pages: sidebar + topbar layout --}}
            <div class="app-content-wrapper" id="appContentWrapper">
                {{-- Mobile top bar --}}
                <div class="mobile-topbar d-lg-none">
                    <div class="d-flex align-items-center gap-2">
                        <button class="hamburger-btn" type="button" onclick="openSidebar()" aria-label="Open menu">
                            <i class="bi bi-list"></i>
                        </button>
                        <span class="fw-semibold text-dark" style="font-size: 0.85rem;">@yield('title', 'IMS')</span>
                    </div>
                    <a href="{{ route('portal') }}">
                        <img src="{{ asset('images/logo_ims.png') }}" alt="IMS" style="height: 22px;">
                    </a>
                </div>

                {{-- Desktop slim top bar --}}
                <div class="app-topbar d-none d-lg-flex">
                    <div class="topbar-title">
                        <span>@yield('title', 'IMS')</span>
                        <span class="topbar-portal-label">
                            @if (request()->is('wetstock*'))
                                Wet Stock
                            @else
                                Sales Documentation
                            @endif
                        </span>
                    </div>
                    <div class="topbar-actions">
                        @stack('page-actions')
                    </div>
                </div>

                {{-- Main content area --}}
                <div class="container-fluid px-4 py-4 flex-grow-1">
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

                    @if (session('warning'))
                        <div class="alert alert-warning alert-dismissible fade show rounded-4 shadow-sm mb-4 p-3 border-0" role="alert" style="background-color: #fef9c3; color: #854d0e;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('warning') }}
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
                        &copy; 2026 Doyen Group of Companies. All rights reserved.
                    </div>
                </footer>
            </div>
        @endif
    @else
        {{-- Guest (login page) --}}
        <div class="container my-5 flex-grow-1">
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
                &copy; 2026 Doyen Group of Companies. All rights reserved.
            </div>
        </footer>
    @endauth

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ===== Mobile drawer (preserved) =====
        function openSidebar() {
            document.getElementById('sidebarDrawer').classList.add('open');
            document.getElementById('sidebarOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeSidebar() {
            document.getElementById('sidebarDrawer').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }

        // ===== Desktop sidebar collapse/expand =====
        (function() {
            var sidebar = document.getElementById('appSidebar');
            var toggle = document.getElementById('sidebarToggle');
            var icon = document.getElementById('collapseIcon');
            var wrapper = document.getElementById('appContentWrapper');

            if (!sidebar || !toggle) return;

            // Restore state from localStorage
            var stored = localStorage.getItem('ims_sidebar_collapsed');
            if (stored === '1') {
                sidebar.setAttribute('data-collapsed', '1');
                icon.className = 'bi bi-chevron-right';
                toggle.setAttribute('aria-label', 'Expand sidebar');
                toggle.setAttribute('aria-expanded', 'false');
                if (wrapper) wrapper.classList.add('sidebar-is-collapsed');
            } else {
                sidebar.setAttribute('data-collapsed', '0');
                icon.className = 'bi bi-chevron-left';
                toggle.setAttribute('aria-label', 'Collapse sidebar');
                toggle.setAttribute('aria-expanded', 'true');
                if (wrapper) wrapper.classList.remove('sidebar-is-collapsed');
            }

            toggle.addEventListener('click', function() {
                var isCollapsed = sidebar.getAttribute('data-collapsed') === '1';
                if (isCollapsed) {
                    // Expand
                    sidebar.setAttribute('data-collapsed', '0');
                    icon.className = 'bi bi-chevron-left';
                    toggle.setAttribute('aria-label', 'Collapse sidebar');
                    toggle.setAttribute('aria-expanded', 'true');
                    localStorage.setItem('ims_sidebar_collapsed', '0');
                    if (wrapper) wrapper.classList.remove('sidebar-is-collapsed');
                    document.documentElement.removeAttribute('data-sidebar-collapsed');
                } else {
                    // Collapse
                    sidebar.setAttribute('data-collapsed', '1');
                    icon.className = 'bi bi-chevron-right';
                    toggle.setAttribute('aria-label', 'Expand sidebar');
                    toggle.setAttribute('aria-expanded', 'false');
                    localStorage.setItem('ims_sidebar_collapsed', '1');
                    if (wrapper) wrapper.classList.add('sidebar-is-collapsed');
                    document.documentElement.setAttribute('data-sidebar-collapsed', '1');
                }
            });

            // Handle keyboard (Enter/Space)
            toggle.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggle.click();
                }
            });
        })();

        // ===== User menu dropdown =====
        (function() {
            var btn = document.getElementById('userMenuBtn');
            var dropdown = document.getElementById('userDropdown');
            if (!btn || !dropdown) return;

            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = dropdown.classList.contains('open');
                dropdown.classList.toggle('open');
                btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });

            // Close on outside click
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
                    dropdown.classList.remove('open');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });

            // Keyboard support
            btn.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    btn.click();
                }
            });
        })();
    </script>
</body>
</html>
