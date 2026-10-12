@extends('layouts.app')

@section('title', 'Wet Stock Dashboard')

@section('content')
@php
    $products = \App\Models\Order::PRODUCT_TYPES;
    $pc = ['U' => '#22a45d', 'P' => '#e5383b', 'D' => '#f2a900'];
    $canEdit = Auth::user()->canEditModule2();

    // 0% only when truly empty, 100% only when truly full; anything in between shows 1-99%
    $pctOf = function ($v, $c) {
        if ($c <= 0 || $v <= 0) { return 0; }
        if ($v >= $c) { return 100; }
        return (int) max(1, min(99, round($v / $c * 100)));
    };

    // stock level band => [badge classes, label]
    $band = function (int $pct): array {
        if ($pct <= 0) { return ['bg-danger text-white', 'Empty']; }
        if ($pct <= 20) { return ['bg-danger text-white', 'Critical Low']; }
        if ($pct <= 40) { return ['ws-badge-orange', 'Low']; }
        if ($pct <= 60) { return ['ws-badge-yellow', 'Stable']; }
        if ($pct < 100) { return ['bg-success text-white', 'Optimal']; }
        return ['bg-success text-white', 'Full'];
    };
@endphp

<style>
    /* ---------- card grid: 5 per row on wide screens, stepping down on smaller ones ---------- */
    .wsu-grid { display:grid; gap:12px; grid-template-columns:repeat(1, minmax(0, 1fr)); }
    @media (min-width: 576px)  { .wsu-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 992px)  { .wsu-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
    @media (min-width: 1200px) { .wsu-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); } }
    @media (min-width: 1500px) { .wsu-grid { grid-template-columns:repeat(5, minmax(0, 1fr)); } }

    /* ---------- compact unit card ---------- */
    .wsu { background:#fff; border:1px solid #e3e8ef; border-radius:10px; padding:12px 14px; height:100%; display:flex; flex-direction:column; gap:8px; transition:border-color .15s, box-shadow .15s; }
    .wsu:hover { border-color:#c5cedb; box-shadow:0 3px 10px rgba(20,33,61,.07); }
    .wsu.is-warn { border-color:#f0b429; }
    .wsu.is-bad { border-color:#d62839; }
    .wsu-name { font-size:.88rem; font-weight:700; color:#14213d; line-height:1.2; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .wsu-sub { font-size:.7rem; color:#6b7686; white-space:nowrap; }
    .wsu-pill { display:inline-block; padding:.1rem .5rem; border-radius:99px; font-size:.65rem; font-weight:600; color:#fff; white-space:nowrap; }
    .wsu-pill-none { background:#eef1f5; color:#6b7686; border:1px solid #dde3ea; }
    .wsu-badge { font-size:.6rem !important; font-weight:600; padding:.25em .55em; white-space:nowrap; }
    .wsu-liters { font-size:1.15rem; font-weight:700; color:#14213d; line-height:1; }
    .wsu-liters small { font-size:.7rem; font-weight:500; color:#6b7686; margin-left:2px; }
    .wsu-meter { height:6px; border-radius:99px; background:#eef1f5; overflow:hidden; }
    .wsu-meter > span { display:block; height:100%; border-radius:99px; }
    .wsu-flag { display:inline-flex; align-items:center; width:fit-content; max-width:100%; font-size:.66rem; font-weight:600; border-radius:99px; padding:.15rem .55rem; text-decoration:none; }
    .ws-badge-orange { background:#f97316; color:#fff; }
    .ws-badge-yellow { background:#facc15; color:#422006; }
    .wsu-flag-bad { background:#fde8ea; color:#b4232f; }
    .wsu-flag-warn { background:#fff3cd; color:#7a5b00; }
    .wsu-flag-orange { background:#ffe8d1; color:#b34700; }
    .wsu-foot { display:flex; justify-content:space-between; align-items:center; gap:6px; margin-top:auto; padding-top:8px; border-top:1px solid #eef1f5; font-size:.7rem; color:#6b7686; line-height:1.35; }
    .wsu-foot .btn { font-size:.72rem; padding:.2rem .65rem; }
    .wsu-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; flex:none; }
    .wsu-prod { display:grid; grid-template-columns:1fr auto; column-gap:8px; row-gap:2px; font-size:.72rem; color:#14213d; }
    .wsu-prod .wsu-meter { grid-column:1 / -1; height:4px; }
    .wsu-prod-l { font-weight:600; }

    /* ---------- warehouse header + summary tiles ---------- */
    .ws-sum { padding:10px 14px; border-radius:10px; }
    .ws-sum .k { font-size:.65rem; letter-spacing:.04em; text-transform:uppercase; font-weight:700; color:#6b7686; }
    .ws-sum .v { font-size:1.15rem; font-weight:700; line-height:1.2; margin-top:2px; }
    .ws-sum .v small { font-size:.75rem; font-weight:500; color:#6b7686; }

    /* ---------- product-aware summary cards (slim) ---------- */
    .ws-sum2 { background:#fff; border:1px solid #e3e8ef; border-radius:10px; padding:8px 12px; height:100%; }
    .ws-sum2-head { display:flex; align-items:center; gap:8px; padding-bottom:6px; margin-bottom:4px; border-bottom:1px solid #eef1f5; }
    .ws-sum2-ico { width:22px; height:22px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-size:.72rem; flex:none; }
    .ws-sum2 .k { font-size:.62rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#6b7686; flex:1; line-height:1.15; }
    .ws-sum2 .t { font-size:.9rem; font-weight:700; color:#14213d; white-space:nowrap; line-height:1; }
    .ws-sum2 .t small { font-size:.62rem; font-weight:500; color:#6b7686; }
    .ws-pr { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:2px 0; font-size:.74rem; color:#4b5563; line-height:1.4; }
    .ws-pr b { font-weight:600; color:#14213d; font-variant-numeric:tabular-nums; }
    .ws-pr-x { color:#9aa5b4; }
    .ws-pr-x b { color:#6b7686; font-weight:500; }
    .ws-pc { width:15px; height:15px; border-radius:4px; display:inline-flex; align-items:center; justify-content:center; font-size:.52rem; font-weight:700; flex:none; }
    .ws-pc-D { background:#e0e7ff; color:#3730a3; }
    .ws-pc-U { background:#dcfce7; color:#166534; }
    .ws-pc-P { background:#fee2e2; color:#991b1b; }
    .ws-pc-X { background:#eef1f5; color:#6b7686; }

    /* ---------- pop-up (unit detail) ---------- */
    .ws-ico { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:#eef3ff; color:#2a5bd7; font-size:1.2rem; flex:none; }
    .ws-stat { border:1px solid #e3e8ef; border-radius:10px; padding:10px 12px; background:#fafbfc; height:100%; }
    .ws-stat .k { font-size:.72rem; color:#6b7686; }
    .ws-stat .v { font-size:1.15rem; font-weight:700; color:#14213d; }
    .ws-truck { background:#f6f8fb; border:1px solid #e3e8ef; border-radius:12px; padding:10px; }
    .cmp-g { cursor:pointer; }
    .cmp-g:focus { outline:none; }
    .cmp-g.is-selected .cmp-shell, .cmp-g:focus-visible .cmp-shell { stroke:#0d6efd !important; stroke-width:3 !important; }
    .ws-cmp { display:grid; grid-template-columns:40px minmax(90px,1.1fr) minmax(80px,2fr) auto auto; align-items:center; gap:10px; padding:8px 12px; border:1px solid #e3e8ef; border-radius:10px; background:#fff; cursor:pointer; font-size:.85rem; }
    .ws-cmp + .ws-cmp { margin-top:6px; }
    .ws-cmp:hover { border-color:#b9c5d6; }
    .ws-cmp.is-selected { border-color:#0d6efd; background:#f2f7ff; }
    .ws-cmp:focus-visible { outline:2px solid #0d6efd; outline-offset:2px; }
    .ws-cmp-no { font-weight:700; color:#14213d; }
    .ws-cmp-l { color:#6b7686; white-space:nowrap; }
    @media (max-width: 575.98px) { .ws-cmp { grid-template-columns:36px 1fr auto; } .ws-cmp-bar { display:none; } }
</style>

@if ($warehouses->isEmpty())
    <div class="card card-custom p-5 text-center"><p class="text-muted mb-0">No warehouses configured yet.</p></div>
@else
    {{-- Valenzuela always first, then the other warehouses (San Simon) in their usual order --}}
    @foreach ($warehouses->sortBy('name')->values() as $warehouse)
        @php
            // Work out every unit's numbers once; the cards and pop-ups below all reuse them.
            $units = $warehouse->tanks->map(function ($t) use ($pctOf) {
                $stock = (int) $t->stock_available;
                $bad = (int) $t->contaminated_liters;
                $comps = $t->compartments->map(function ($c) {
                    return (object) [
                        'id' => $c->id,
                        'no' => $c->compartment_no,
                        'product' => $c->product_type,
                        'level' => (int) $c->stock_available,
                        'cap' => (int) $c->capacity,
                        'bad' => (int) $c->contaminated_liters,
                        'note' => $c->contamination_note,
                    ];
                });
                $placed = (int) $comps->sum('level');

                return (object) [
                    'id' => $t->id,
                    'name' => $t->name,
                    'tanker' => $t->category === 'tanker',
                    'active' => (bool) $t->is_active,
                    'product' => $t->product_type,
                    'product_name' => $t->product_name,
                    'cap' => (int) $t->max_capacity,
                    'stock' => $stock,
                    'hold' => (int) $t->stock_for_delivery,
                    'bad' => $bad,
                    'sellable' => max(0, $stock - $bad),
                    'fully_bad' => $bad > 0 && $bad >= $stock,
                    'pct' => $pctOf($stock, $t->max_capacity),
                    'note' => $t->remarks,
                    'comps' => $comps,
                    'unassigned' => $comps->count() ? max(0, $stock - $placed) : 0,
                ];
            });
            $active = $units->where('active', true);
            $depots = $active->where('tanker', false);
            $tankers = $active->where('tanker', true);

            // Product-aware totals for the three summary cards (stock / hold / capacity per product)
            $order = ['D', 'U', 'P'];
            $tot = ['stock' => (int) $active->sum('stock'), 'hold' => (int) $active->sum('hold'), 'cap' => (int) $active->sum('cap')];
            $by = [];
            foreach ($order as $k) { $by[$k] = ['stock' => 0, 'hold' => 0, 'cap' => 0]; }
            foreach ($active as $u) {
                if (!$u->tanker) {
                    if (isset($by[$u->product])) {
                        $by[$u->product]['stock'] += $u->stock;
                        $by[$u->product]['hold'] += $u->hold;
                        $by[$u->product]['cap'] += $u->cap;
                    }
                } else {
                    foreach ($u->comps as $c) {
                        if (isset($by[$c->product])) {
                            $by[$c->product]['stock'] += $c->level;
                            $by[$c->product]['cap'] += $c->cap;
                        }
                    }
                }
            }
            // whatever is not tied to a product (no product set, truck stock outside compartments, truck hold) shows as "Unassigned"
            $by['X'] = [];
            foreach (['stock', 'hold', 'cap'] as $m) {
                $by['X'][$m] = max(0, $tot[$m] - array_sum(array_map(fn ($k) => $by[$k][$m], $order)));
            }
            $metrics = [
                ['Total Stock Available', 'stock', 'bi-droplet-half', '#16a34a', '#e6f7ec'],
                ['Stock for Delivery (Hold)', 'hold', 'bi-truck', '#a16207', '#fff4d6'],
                ['Total Capacity', 'cap', 'bi-database', '#475569', '#eef1f5'],
            ];
        @endphp

        <div class="card card-custom p-3 mb-4 border-0 shadow-sm">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom flex-wrap gap-2">
                <div class="d-flex align-items-center">
                    <h5 class="fw-bold text-dark mb-0 me-3"><i class="bi bi-building text-primary me-2"></i>{{ $warehouse->name }} Warehouse</h5>
                    <span class="badge bg-light text-dark border rounded-pill px-3 py-1 fw-normal">{{ $active->count() }} Active Units</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#units-{{ $warehouse->id }}"><i class="bi bi-gear me-1"></i> Add/Manage Tank</button>
            </div>

            <div class="row g-2 mb-3">
                @foreach ($metrics as [$mLabel, $mKey, $mIcon, $mFg, $mBg])
                    <div class="col-12 col-md-4">
                        <div class="ws-sum2">
                            <div class="ws-sum2-head">
                                <span class="ws-sum2-ico" style="background:{{ $mBg }};color:{{ $mFg }};"><i class="bi {{ $mIcon }}"></i></span>
                                <span class="k">{{ $mLabel }}</span>
                                <span class="t">{{ number_format($tot[$mKey]) }} <small>L</small></span>
                            </div>
                            @foreach ($order as $code)
                                <div class="ws-pr">
                                    <span class="d-flex align-items-center gap-2"><span class="ws-pc ws-pc-{{ $code }}">{{ $code }}</span>{{ $products[$code] ?? $code }}</span>
                                    <b>{{ number_format($by[$code][$mKey]) }}</b>
                                </div>
                            @endforeach
                            @if ($by['X'][$mKey] > 0)
                                <div class="ws-pr ws-pr-x">
                                    <span class="d-flex align-items-center gap-2"><span class="ws-pc ws-pc-X">–</span>Unassigned</span>
                                    <b>{{ number_format($by['X'][$mKey]) }}</b>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($active->isEmpty())
                {{-- There is no separate "create tank" page any more: the
                     dashboard's Tanks & tankers modal handles creation. --}}
                <p class="text-muted text-center py-3 mb-0">
                    No active storage tanks or tankers in {{ $warehouse->name }}.
                    @if ($canEdit)
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline js-open-add" data-wh="{{ $warehouse->id }}">Add a unit</button>
                        to start tracking fuel.
                    @endif
                </p>
            @else
                <ul class="nav nav-pills gap-2 mb-3">
                    <li class="nav-item"><button class="nav-link active py-1 px-3 small" data-bs-toggle="pill" data-bs-target="#dep-{{ $warehouse->id }}" data-ws-wh="{{ $warehouse->id }}" data-ws-tab="dep" type="button"><i class="bi bi-box-seam me-1"></i>Depots <span class="badge bg-light text-dark ms-1">{{ $depots->count() }}</span></button></li>
                    <li class="nav-item"><button class="nav-link py-1 px-3 small" data-bs-toggle="pill" data-bs-target="#trk-{{ $warehouse->id }}" data-ws-wh="{{ $warehouse->id }}" data-ws-tab="trk" type="button"><i class="bi bi-truck me-1"></i>Tanker Trucks <span class="badge bg-light text-dark ms-1">{{ $tankers->count() }}</span></button></li>
                </ul>

                <div class="tab-content">
                    {{-- ================= DEPOTS ================= --}}
                    <div class="tab-pane fade show active" id="dep-{{ $warehouse->id }}">
                        @if ($depots->isEmpty())
                            <p class="text-muted text-center py-3 mb-0">No depot tanks here.</p>
                        @else
                            <div class="wsu-grid">
                                @foreach ($depots as $u)
                                    @php
                                        [$bb, $bl] = $band($u->pct);
                                        $col = $pc[$u->product] ?? '#9aa5b4';
                                    @endphp
                                    <div class="wsu {{ $u->fully_bad ? 'is-bad' : ($u->bad ? 'is-warn' : '') }}">
                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <div class="wsu-name">{{ $u->name }}</div>
                                            <span class="badge wsu-badge {{ $bb }}">{{ $bl }}</span>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            @if ($u->product)
                                                <span class="wsu-pill" style="background:{{ $col }};">{{ $u->product_name }}</span>
                                            @else
                                                <span class="wsu-pill wsu-pill-none">No product set</span>
                                            @endif
                                            <span class="wsu-sub">{{ number_format($u->cap) }} L max</span>
                                        </div>

                                        <div>
                                            <div class="d-flex justify-content-between align-items-baseline mb-1">
                                                <span class="wsu-liters">{{ number_format($u->stock) }}<small>L</small></span>
                                                <span class="wsu-sub">{{ $u->pct }}%</span>
                                            </div>
                                            <div class="wsu-meter"><span style="width:{{ $u->pct }}%;background:{{ $col }};"></span></div>
                                        </div>

                                        @if ($u->bad)
                                            <span class="wsu-flag {{ $u->fully_bad ? 'wsu-flag-bad' : 'wsu-flag-warn' }}">⚠ {{ number_format($u->bad) }} L contaminated</span>
                                        @elseif ($u->pct <= 40 && $canEdit)
                                            <a href="{{ route('wetstock.stock-requests.index', ['create' => 1]) }}" class="wsu-flag {{ $u->pct <= 20 ? 'wsu-flag-bad' : 'wsu-flag-orange' }}">Request stock</a>
                                        @endif

                                        <div class="wsu-foot">
                                            <div>Hold {{ number_format($u->hold) }} L<br>Sellable {{ number_format($u->sellable) }} L</div>
                                            <button type="button" class="btn btn-sm {{ $canEdit ? 'btn-outline-primary' : 'btn-outline-secondary' }}" data-bs-toggle="modal" data-bs-target="#unit-{{ $u->id }}">{{ $canEdit ? 'Manage' : 'View' }}</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- ================= TANKER TRUCKS ================= --}}
                    <div class="tab-pane fade" id="trk-{{ $warehouse->id }}">
                        @if ($tankers->isEmpty())
                            <p class="text-muted text-center py-3 mb-0">No tanker trucks here.</p>
                        @else
                            <div class="wsu-grid">
                                @foreach ($tankers as $u)
                                    @php
                                        [$bb, $bl] = $band($u->pct);
                                        $n = $u->comps->count();
                                        $byProd = $u->comps->where('level', '>', 0)->filter(fn ($c) => $c->product)->groupBy('product')->map(fn ($g) => $g->sum('level'));
                                    @endphp
                                    <div class="wsu {{ $u->fully_bad ? 'is-bad' : ($u->bad ? 'is-warn' : '') }}">
                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <div class="wsu-name"><i class="bi bi-truck text-info me-1"></i>{{ $u->name }}</div>
                                            <span class="badge wsu-badge {{ $bb }}">{{ $bl }}</span>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <span class="wsu-sub">{{ $n }} compartment{{ $n === 1 ? '' : 's' }}</span>
                                            <span class="wsu-sub">{{ number_format($u->cap) }} L max</span>
                                        </div>

                                        <div>
                                            <div class="d-flex justify-content-between align-items-baseline mb-1">
                                                <span class="wsu-liters">{{ number_format($u->stock) }}<small>L</small></span>
                                                <span class="wsu-sub">{{ $u->pct }}%</span>
                                            </div>
                                            <div class="wsu-meter"><span style="width:{{ $u->pct }}%;background:#64748b;"></span></div>
                                        </div>

                                        @if ($n === 0)
                                            <div class="wsu-sub">No compartments set up yet.</div>
                                        @else
                                            <div class="d-flex flex-column gap-2">
                                                @forelse ($byProd as $code => $liters)
                                                    <div class="wsu-prod">
                                                        <span><span class="wsu-dot" style="background:{{ $pc[$code] ?? '#9aa5b4' }};"></span>{{ $products[$code] ?? $code }}</span>
                                                        <span class="wsu-prod-l">{{ number_format($liters) }} L</span>
                                                        <div class="wsu-meter"><span style="width:{{ $pctOf($liters, $u->cap) }}%;background:{{ $pc[$code] ?? '#9aa5b4' }};"></span></div>
                                                    </div>
                                                @empty
                                                    <div class="wsu-sub">Empty</div>
                                                @endforelse
                                            </div>
                                        @endif

                                        @if ($u->bad)
                                            <span class="wsu-flag {{ $u->fully_bad ? 'wsu-flag-bad' : 'wsu-flag-warn' }}">⚠ {{ number_format($u->bad) }} L contaminated</span>
                                        @endif
                                        @if ($u->unassigned > 0)
                                            <span class="wsu-flag wsu-flag-warn">{{ number_format($u->unassigned) }} L not in a compartment</span>
                                        @endif

                                        <div class="wsu-foot">
                                            <div>Hold {{ number_format($u->hold) }} L<br>Sellable {{ number_format($u->sellable) }} L</div>
                                            <button type="button" class="btn btn-sm {{ $canEdit ? 'btn-outline-primary' : 'btn-outline-secondary' }}" data-bs-toggle="modal" data-bs-target="#unit-{{ $u->id }}">{{ $canEdit ? 'Manage' : 'View' }}</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- Pop-ups live outside the card so tab transitions never clip them --}}
        @include('wetstock.partials.units-modal', ['warehouse' => $warehouse, 'units' => $units, 'canEdit' => $canEdit])
        @foreach ($active as $u)
            @include('wetstock.partials.unit-modal', ['u' => $u, 'products' => $products, 'pc' => $pc, 'canEdit' => $canEdit, 'band' => $band, 'pctOf' => $pctOf])
        @endforeach
    @endforeach
@endif

@php
    $flash = [];
    foreach (['success', 'danger', 'warning'] as $k) {
        if (session($k)) { $flash[] = [$k, session($k)]; }
    }
    if (isset($errors) && $errors->any()) { $flash[] = ['danger', $errors->first()]; }
@endphp
@if (count($flash))
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1090;">
        @foreach ($flash as [$cls, $msg])
            <div class="toast align-items-center text-bg-{{ $cls }} border-0" role="alert" data-bs-autohide="{{ $cls === 'danger' ? 'false' : 'true' }}" data-bs-delay="6000">
                <div class="d-flex">
                    <div class="toast-body">{{ $msg }}</div>
                    <button type="button" class="btn-close {{ $cls === 'warning' ? '' : 'btn-close-white' }} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        @endforeach
    </div>
@endif

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var P = @json($products);
        var fmt = function (n) { return Number(n).toLocaleString('en-US'); };

        document.querySelectorAll('.toast').forEach(function (t) { bootstrap.Toast.getOrCreateInstance(t).show(); });

        // ---- keep the Depots / Tanker Trucks tab and the scroll position after every update
        function activateTab(btn) {
            btn.closest('.nav').querySelectorAll('.nav-link').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var pane = document.querySelector(btn.dataset.bsTarget);
            if (!pane) { return; }
            pane.parentElement.querySelectorAll(':scope > .tab-pane').forEach(function (p) { p.classList.remove('show', 'active'); });
            pane.classList.add('show', 'active');
        }
        function rememberTab(btn) {
            try { sessionStorage.setItem('ws-tab-' + btn.dataset.wsWh, btn.dataset.wsTab); } catch (e) {}
        }
        function rememberScroll() {
            try { sessionStorage.setItem('ws-scroll', String(window.pageYOffset || document.documentElement.scrollTop || 0)); } catch (e) {}
        }

        document.querySelectorAll('[data-ws-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () { rememberTab(btn); });
            var saved = null;
            try { saved = sessionStorage.getItem('ws-tab-' + btn.dataset.wsWh); } catch (e) {}
            if (saved && saved === btn.dataset.wsTab && !btn.classList.contains('active')) { activateTab(btn); }
        });

        // forms in the pop-ups reload the dashboard: remember where the page was right before they submit
        // ...and which pop-up / tab / compartment was open, so it reopens exactly there after the reload
        document.addEventListener('submit', function (e) {
            var m = e.target.closest('.ws-modal, .ws-list');
            if (!m) { return; }
            rememberScroll();
            try {
                var sel = m.querySelector('.js-cmp.is-selected');
                var act = m.querySelector('.mm-tab.active');
                var bd = m.querySelector('.modal-body');
                sessionStorage.setItem('ws-modal', JSON.stringify({
                    id: m.id,
                    cid: sel ? sel.dataset.cid : null,
                    pane: act ? act.dataset.pane : null,
                    top: bd ? bd.scrollTop : 0
                }));
            } catch (er) {}
        });

        var savedY = null;
        try { savedY = sessionStorage.getItem('ws-scroll'); sessionStorage.removeItem('ws-scroll'); } catch (e) {}
        if (savedY !== null) {
            var jump = function () { window.scrollTo(0, parseInt(savedY, 10) || 0); };
            if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
            jump();
            window.addEventListener('load', function () {
                jump();
                if ('scrollRestoration' in history) { history.scrollRestoration = 'auto'; }
            }, { once: true });
        }

        // ---- "Manage" inside the Tanks & Tankers list: close the list, then open that unit's pop-up
        document.addEventListener('click', function (e) {
            var b = e.target.closest('.js-open-unit');
            if (!b) { return; }
            var list = b.closest('.modal');
            var target = document.getElementById(b.dataset.unit);
            if (!list || !target) { return; }
            list.addEventListener('hidden.bs.modal', function () {
                bootstrap.Modal.getOrCreateInstance(target).show();
            }, { once: true });
            bootstrap.Modal.getOrCreateInstance(list).hide();
        });

        // ---- one controller per unit pop-up
        document.querySelectorAll('.ws-modal').forEach(function (root) {
            var q = function (s) { return root.querySelector(s); };
            var qa = function (s) { return root.querySelectorAll(s); };
            var solo = root.dataset.solo === '1';
            var acting = !!q('.mm-target');   // view-only users have no action forms

            function pane(name) {
                qa('.mm-pane').forEach(function (p) { p.classList.toggle('d-none', p.dataset.pane !== name); });
                qa('.mm-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.pane === name); });
                // the Edit tab replaces the read-only view with the form
                qa('.ws-view').forEach(function (v) { v.classList.toggle('d-none', name === 'edit'); });
                var tg = q('.mm-target'); if (tg) { tg.classList.toggle('invisible', name === 'edit'); }
                var bd = q('.mm-bad'); if (bd && name === 'edit') { bd.classList.add('d-none'); }
            }
            qa('.mm-tab').forEach(function (b) { b.addEventListener('click', function () { pane(b.dataset.pane); }); });

            // stop a double-click from posting stock twice
            qa('.mm-form').forEach(function (f) {
                f.addEventListener('submit', function () {
                    var btn = f.querySelector('button[type=submit]');
                    setTimeout(function () { if (btn) { btn.disabled = true; } }, 0);
                });
            });

            // ---- Edit tab: compartment rows (trucks only)
            qa('.ws-edit-form').forEach(function (f) {
                var rows = f.querySelector('.ee-rows');
                if (!rows) { return; }
                var next = rows.children.length;
                function renumber() {
                    var total = 0;
                    rows.querySelectorAll('.ee-row').forEach(function (row, i) {
                        row.querySelector('.ee-no').textContent = 'C' + (i + 1);
                        total += parseInt(row.querySelector('.ee-cap').value || 0, 10);
                    });
                    f.querySelector('.ee-sum').textContent = total.toLocaleString('en-US');
                }
                f.querySelector('.ee-add').addEventListener('click', function () {
                    rows.insertAdjacentHTML('beforeend', f.querySelector('.ee-tpl').innerHTML.replace(/__i__/g, next++));
                    renumber();
                });
                rows.addEventListener('click', function (e) {
                    var del = e.target.closest('.ee-del');
                    if (del && rows.children.length > 1) { del.closest('.ee-row').remove(); renumber(); }
                });
                rows.addEventListener('input', renumber);
                renumber();
            });

            function current() {
                if (solo) { return root.dataset; }
                var s = q('.js-cmp.is-selected');
                return s ? s.dataset : null;
            }

            function apply() {
                if (!acting) { return; }
                var d = current();
                if (!d) { return; }

                var level = +d.level, cap = +d.cap, bad = +d.bad, un = +d.unassigned || 0;
                var blocked = bad > 0 && bad >= level;
                var prodLabel = level > 0 && d.product ? (P[d.product] || d.product) : 'Empty';

                q('.mm-target').textContent = (solo ? '' : 'Compartment C' + d.no + ' · ') + fmt(level) + ' of ' + fmt(cap) + ' L · ' + prodLabel;
                qa('input[name=compartment_id]').forEach(function (i) { i.value = solo ? '' : d.cid; });

                var box = q('.mm-bad');
                box.classList.toggle('d-none', bad < 1);
                box.textContent = bad > 0 ? '⚠ ' + fmt(bad) + ' L contaminated' + (d.note ? ': ' + d.note : '') + '. Blocked from sellable stock.' : '';

                // ADD: product is locked while there is stock; unassigned truck stock can be moved in
                var prod = q('.mm-prod select');
                prod.value = d.product || prod.options[0].value;
                q('.mm-prod').classList.toggle('d-none', level > 0);

                var srcSel = q('.mm-src select');
                srcSel.value = 'new';
                q('.mm-src').classList.toggle('d-none', !(!solo && un > 0));

                var addQty = q('[data-pane=add] input[name=quantity]');
                addQty.value = '';
                function refreshAdd() {
                    var legacy = srcSel.value === 'legacy';
                    var room = cap - level;
                    var max = legacy ? Math.min(un, room) : room;
                    addQty.max = Math.max(0, max);
                    q('.mm-room').textContent = legacy
                        ? '(unassigned: ' + fmt(un) + ' L, room: ' + fmt(room) + ' L)'
                        : '(room: ' + fmt(room) + ' L)';
                    q('.mm-submit-add').disabled = blocked || max < 1;
                    q('.mm-submit-add').textContent = legacy ? 'Move into compartment' : 'Add stock';
                }
                srcSel.onchange = refreshAdd;
                refreshAdd();

                // REDUCE
                var redQty = q('[data-pane=reduce] input[name=quantity]');
                redQty.max = level;
                redQty.value = '';
                q('.mm-have').textContent = '(available: ' + fmt(level) + ' L)';
                q('[data-pane=reduce] button[type=submit]').disabled = level < 1;

                // CONTAMINATION
                var clearing = bad > 0;
                q('.mm-liters').classList.toggle('d-none', clearing || !solo);
                q('.mm-note').classList.toggle('d-none', clearing);
                var lit = q('.mm-liters input');
                lit.max = level;
                lit.value = level;
                q('.mm-contam-btn').textContent = clearing ? 'Clear contamination flag' : (!solo ? 'Quarantine this compartment' : 'Flag as contaminated');
                q('.mm-contam-btn').disabled = !clearing && level < 1;
                q('.mm-contam-help').textContent = clearing
                    ? 'Clear the flag after testing passes and the stock is cleared for sale.'
                    : (!solo ? 'Only this compartment is quarantined. The other compartments stay sellable.' : 'Enter how many liters are affected. Only that volume is blocked.');
            }

            function select(el) {
                qa('.js-cmp').forEach(function (x) { x.classList.toggle('is-selected', x.dataset.cid === el.dataset.cid); });
                apply();
            }

            root.addEventListener('click', function (e) {
                var c = e.target.closest('.js-cmp');
                if (c) { select(c); }
            });
            root.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') { return; }
                var c = e.target.closest('.js-cmp');
                if (c) { e.preventDefault(); select(c); }
            });

            root.addEventListener('show.bs.modal', function () {
                var first = q('.js-cmp');
                if (first) { select(first); } else { apply(); }
                var firstTab = q('.mm-tab');
                pane(firstTab ? firstTab.dataset.pane : 'add');
            });

            // used to reopen this pop-up on the same compartment + tab after an update
            root._wsRestore = function (cid, name) {
                if (cid) {
                    var el = root.querySelector('.js-cmp[data-cid="' + cid + '"]');
                    if (el) { select(el); }
                }
                if (name && q('.mm-tab[data-pane="' + name + '"]')) { pane(name); }
            };
        });

        // ---- after an update the page reloads: reopen the pop-up that was open, no flicker
        var st = null;
        try { st = JSON.parse(sessionStorage.getItem('ws-modal') || 'null'); sessionStorage.removeItem('ws-modal'); } catch (e) {}
        if (st && st.id) {
            var mEl = document.getElementById(st.id);
            if (mEl) {
                var hadFade = mEl.classList.contains('fade');
                mEl.classList.remove('fade');
                mEl.addEventListener('show.bs.modal', function () {
                    if (mEl._wsRestore) { mEl._wsRestore(st.cid, st.pane); }
                }, { once: true });
                mEl.addEventListener('shown.bs.modal', function () {
                    if (hadFade) { mEl.classList.add('fade'); }
                    var b = mEl.querySelector('.modal-body');
                    if (b) { b.scrollTop = st.top || 0; }
                }, { once: true });
                bootstrap.Modal.getOrCreateInstance(mEl).show();
            }
        }
    });
</script>
@endsection