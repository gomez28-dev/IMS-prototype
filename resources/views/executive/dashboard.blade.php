@extends('layouts.app')

@section('title', 'Executive Overview')

@section('hide-portal-label', true)

@section('content')
<style>
    .exec-hero {
        background: linear-gradient(120deg, #0f172a 0%, #1e293b 55%, #334155 100%);
        color: #fff;
        border-radius: 1.25rem;
        padding: 1.4rem 1.6rem;
    }
    .exec-hero .exec-period { color: #cbd5e1; font-size: .8rem; letter-spacing: .04em; text-transform: uppercase; }
    .exec-card { border: 0; border-radius: 1rem; box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 8px 24px -18px rgba(15, 23, 42, .35); height: 100%; }
    .exec-card .exec-icon {
        width: 38px; height: 38px; border-radius: .75rem; display: inline-flex;
        align-items: center; justify-content: center; font-size: 1.05rem;
        background: #f1f5f9; color: #334155;
    }
    .exec-figure { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; padding: .35rem 0; border-bottom: 1px dashed #eef2f7; }
    .exec-figure:last-child { border-bottom: 0; }
    .exec-figure .exec-label { color: #64748b; font-size: .78rem; }
    .exec-figure .exec-value { font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .exec-figure .exec-value small { font-weight: 600; color: #64748b; font-size: .68rem; margin-left: .15rem; }
    .exec-hint { color: #94a3b8; font-size: .68rem; }
    .exec-attention-item { display: flex; align-items: center; gap: .75rem; padding: .65rem .25rem; border-bottom: 1px solid #f1f5f9; }
    .exec-attention-item:last-child { border-bottom: 0; }
    .exec-attention-item .exec-count { min-width: 2.1rem; text-align: center; font-weight: 700; border-radius: .6rem; padding: .2rem .4rem; background: #f8fafc; color: #334155; }
    .exec-attention-item.is-clear .exec-count { background: #ecfdf5; color: #047857; }
    .exec-attention-item.is-open .exec-count { background: #fef2f2; color: #b91c1c; }
</style>

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h2 class="fw-bold text-dark mb-1">Executive Overview</h2>
        <p class="text-muted mb-0 small">
            {{ $summary['period_label'] }} across all four modules &middot; operations for {{ $summary['today_label'] }}
        </p>
    </div>
    <a href="{{ route('portal') }}" class="btn btn-outline-dark btn-sm rounded-pill px-3">
        <i class="bi bi-grid me-1"></i> Switch Portal
    </a>
</div>

<div class="row g-3 mb-4">
    @foreach (['sales', 'wet_stock', 'stock_orders', 'logistics'] as $moduleKey)
        @php $module = $summary[$moduleKey]; @endphp
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card exec-card">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="exec-icon"><i class="bi {{ $module['icon'] }}"></i></span>
                        <div>
                            <div class="fw-semibold text-dark">{{ $module['title'] }}</div>
                            <div class="exec-hint">
                                {{ $moduleKey === 'logistics' ? 'Today' : ($moduleKey === 'wet_stock' ? 'On hand now' : 'This month') }}
                            </div>
                        </div>
                    </div>

                    <div class="flex-grow-1">
                        @foreach ($module['figures'] as $figure)
                            @php
                                $isMoney = $figure['unit'] === '₱';
                                $formatted = $isMoney
                                    ? '₱' . number_format((float) $figure['value'], 2)
                                    : ($figure['unit'] === 'L'
                                        ? number_format((float) $figure['value'])
                                        : number_format((float) $figure['value']));
                            @endphp
                            <div class="exec-figure">
                                <span class="exec-label">
                                    {{ $figure['label'] }}
                                    @if ($figure['hint'])
                                        <span class="d-block exec-hint">{{ $figure['hint'] }}</span>
                                    @endif
                                </span>
                                <span class="exec-value">
                                    {{ $formatted }}@if ($figure['unit'] === 'L')<small>L</small>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <a href="{{ $module['url'] }}" class="btn btn-primary-custom btn-sm mt-3 w-100">
                        Open {{ $module['title'] }} <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card exec-card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-1">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Needs attention</h5>
            <span class="exec-hint">Only what your role can act on</span>
        </div>

        @forelse ($summary['attention'] as $item)
            <div class="exec-attention-item {{ $item['count'] > 0 ? 'is-open' : 'is-clear' }}">
                <span class="exec-count">{{ number_format($item['count']) }}</span>
                <div class="flex-grow-1">
                    <div class="fw-semibold text-dark small">{{ $item['label'] }}</div>
                    <div class="exec-hint">{{ $item['hint'] }}</div>
                </div>
                <a href="{{ $item['url'] }}" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                    Review <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        @empty
            <p class="text-muted small mb-0">Nothing waiting for you right now.</p>
        @endforelse
    </div>
</div>
@endsection
