@extends('layouts.app')

@section('title', 'Sales Orders')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('portal') }}" class="text-decoration-none text-secondary">Portal</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('stock-orders.purchase-orders.index') }}" class="text-decoration-none text-secondary">Stock Orders</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Sales Orders</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="bi bi-patch-check text-primary me-2"></i>Sales Orders Needing an ATL
                </h3>
                <p class="text-muted small mb-0 mt-1">Fuel Trade and Buy Back pick-up orders, whatever their clearance status.</p>
            </div>
            {{-- A "Stock Orders Dashboard" button used to sit here. It linked to
                 stock-orders.purchase-orders.index - the Purchase Orders page -
                 under a label that promised somewhere else entirely, so it is
                 gone rather than relabelled. --}}
        </div>

        @php
            // Product chip colours: U = Unleaded, D = Diesel, P = Premium.
            // Kept identical to the Orders Dashboard, Purchase Orders and
            // Stock Requests so all the pages read as one set.
            $chipStyles = [
                'U' => 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;',
                'D' => 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;',
                'P' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
            ];

            $chip = function ($code, $title = '') use ($chipStyles) {
                $style = $chipStyles[$code] ?? 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';

                return '<span class="prod-chip" style="'.$style.'" title="'.e($title).'">'.e($code ?: '—').'</span>';
            };

            $prodRows = [['D', 'Diesel'], ['U', 'Unleaded'], ['P', 'Premium']];

            // Pending Clearance is a count of orders rather than a volume, so
            // it has no product dimension; it sits under the headline count.
            $statCards = [
                ['title' => 'Total Qty. Ordered', 'icon' => 'bi-boxes', 'tint' => 'background:#ecfeff;color:#0891b2;', 'data' => $stats['volumes']['ordered']],
                ['title' => 'Unlifted ATLs', 'icon' => 'bi-clock-history', 'tint' => 'background:#fffbeb;color:#d97706;', 'data' => $stats['volumes']['unlifted']],
                ['title' => 'Lifted ATLs', 'icon' => 'bi-truck', 'tint' => 'background:#f0fdf4;color:#16a34a;', 'data' => $stats['volumes']['lifted']],
            ];
        @endphp
        <style>
            .stat-title {
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                color: #374151;
            }
            .stat-icon {
                width: 46px;
                height: 46px;
                border-radius: 0.8rem;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.3rem;
                flex-shrink: 0;
            }
            .stat-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border: 1px solid #e5e7eb;
                border-radius: 0.6rem;
                padding: 0.45rem 0.8rem;
                margin-bottom: 0.45rem;
                background: #fff;
            }
            .stat-row .stat-val { font-weight: 700; color: #111827; }
            .stat-total {
                display: flex;
                justify-content: space-between;
                padding: 0.25rem 0.3rem 0;
                color: #6b7280;
            }
            .stat-total .stat-val { font-weight: 600; color: #111827; }
            .stat-big { font-size: 3.6rem; font-weight: 800; line-height: 1; color: #111827; }
            .stat-sub { font-size: 0.8rem; color: #6b7280; }
            .prod-chip {
                display: inline-block;
                font-size: 0.65rem;
                font-weight: 700;
                line-height: 1;
                padding: 0.2rem 0.4rem;
                border-radius: 0.35rem;
                margin: 0 1px;
            }
        </style>

        {{-- Stat cards: one headline count, then volume split by product. --}}
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card card-custom p-3 border-0 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon" style="background:#fff1ee;color:#f04a1a;"><i class="bi bi-journal-text"></i></div>
                        <span class="stat-title">Orders Listed</span>
                    </div>
                    <div class="d-flex flex-grow-1 flex-column align-items-center justify-content-center py-3">
                        <span class="stat-big">{{ number_format($stats['orders']) }}</span>
                        <span class="stat-sub mt-2">
                            <span class="text-warning fw-semibold">{{ number_format($stats['pending_clearance']) }}</span> pending clearance
                        </span>
                    </div>
                </div>
            </div>

            @foreach ($statCards as $card)
                <div class="col-xl-3 col-md-6">
                    <div class="card card-custom p-3 border-0 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="stat-icon" style="{{ $card['tint'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                            <span class="stat-title">{{ $card['title'] }}</span>
                        </div>
                        @foreach ($prodRows as [$code, $name])
                            <div class="stat-row">
                                <span>{!! $chip($code, $name) !!} <span class="ms-2">{{ $name }}</span></span>
                                <span class="stat-val">{{ number_format($card['data'][$name] ?? 0) }}</span>
                            </div>
                        @endforeach
                        <div class="stat-total">
                            <span>Total</span>
                            <span class="stat-val">{{ number_format(array_sum($card['data'])) }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Search --}}
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('stock-orders.sales-orders.index') }}" class="row g-2 align-items-center">
                    <div class="col-md-6">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Search by SO# or Account..." value="{{ $search }}">
                    </div>
                    <div class="col-md-6 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Search</button>
                        <a href="{{ route('stock-orders.sales-orders.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Orders --}}
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 py-3">SO Number</th>
                                <th class="py-3">Client / Account</th>
                                <th class="py-3">Qty Ordered / Products</th>
                                <th class="py-3 text-center">Clearance</th>
                                <th class="py-3 text-center">ATL Status</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($orders as $row)
                                @php
                                    $rowAtls = $atlsByOrder[$row->id] ?? [];
                                    $allLifted = count($rowAtls) > 0
                                        && collect($rowAtls)->every(fn($a) => $a->lift_status === \App\Models\PurchaseOrderDelivery::LIFT_LIFTED);
                                    // Per-product volumes, so a multi-product
                                    // order reports each product's own quantity
                                    // rather than one lump total.
                                    $rowVolumes = [];
                                    foreach ($row->items as $item) {
                                        $name = $item->product_name;
                                        $rowVolumes[$name] = ($rowVolumes[$name] ?? 0) + (int) $item->qty;
                                    }
                                @endphp
                                <tr>
                                    <td class="ps-3 fw-bold text-dark">
                                        {{ $row->formatted_so_number }}
                                    </td>
                                    <td><strong>{{ $row->account }}</strong></td>
                                    <td>
                                        @if ($rowVolumes === [])
                                            <span class="text-muted">—</span>
                                            <div class="small text-muted font-monospace">{{ number_format($row->qty_ordered) }} L</div>
                                        @else
                                            @foreach ($rowVolumes as $product => $qty)
                                                @php $code = \Illuminate\Support\Str::substr((string) $product, 0, 1); @endphp
                                                <div class="mb-1">
                                                    {!! $chip($code, $product) !!}
                                                    <span class="ms-2 text-muted small">{{ $product }}</span>
                                                    <span class="fw-bold font-monospace ms-2">{{ number_format($qty) }}</span>
                                                </div>
                                            @endforeach
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @php
                                            $clearanceClass = match ($row->clearing_status) {
                                                'Approved' => 'bg-success-subtle text-success border border-success-subtle',
                                                'Declined' => 'bg-danger-subtle text-danger border border-danger-subtle',
                                                'Hold' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                                default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                            };
                                        @endphp
                                        <span class="badge rounded-pill border px-2 py-1 {{ $clearanceClass }}">
                                            {{ $row->clearing_status ?: 'Pending' }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        @if ($rowAtls === [])
                                            <span class="badge bg-light text-muted border rounded-pill px-2 py-1">No ATL Yet</span>
                                        @elseif ($allLifted)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">
                                                {{ count($rowAtls) }} Lifted
                                            </span>
                                        @else
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1">
                                                {{ count($rowAtls) }} Unlifted
                                            </span>
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end">
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            <a href="{{ route('stock-orders.sales-orders.show', $row->id) }}"
                                               class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1">
                                                ATL Details
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-3 text-secondary"></i>
                                        No Fuel Trade or Buy Back pick-up orders found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($orders->hasPages())
                    <div class="p-3">
                        {{ $orders->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
