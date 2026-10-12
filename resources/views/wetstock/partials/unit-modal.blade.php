{{-- Detail pop-up for one active depot or tanker. Variables: $u, $products, $pc, $canEdit, $band, $pctOf --}}
@php
    [$bb, $bl] = $band($u->pct);
    $col = $pc[$u->product] ?? '#9aa5b4';
    $n = $u->comps->count();
    $w = $n ? 212 / $n : 0;
    $solo = !$u->tanker;
    $room = max(0, $u->cap - $u->stock);
    $canAct = $canEdit && ($solo || $n > 0);

    $kv = function (array $d) {
        $s = '';
        foreach ($d as $k => $v) { $s .= ' data-' . $k . '="' . e($v) . '"'; }
        return $s;
    };
    $soloData = $solo ? $kv([
        'product' => $u->product,
        'level' => $u->stock,
        'cap' => $u->cap,
        'bad' => $u->bad,
        'note' => $u->note,
        'unassigned' => 0,
    ]) : '';
@endphp

@once
<style>
    .modal-dialog.ws-wide { max-width:640px; }
    .ws-modal .modal-header { padding:14px 18px 6px; }
    .ws-modal .modal-body { padding:8px 18px 12px; }
    .ws-modal .modal-footer { padding:4px 18px 14px; }
    .ws-modal .ws-ico { width:34px; height:34px; border-radius:10px; font-size:1rem; }
    .ws-modal .modal-title { font-size:1rem; }
    .ws-modal .badge { font-weight:600; font-size:.65rem; }

    /* depot tank visual + figures */
    .ws-tank { flex:none; width:136px; height:auto; }
    .ws-kv { display:flex; justify-content:space-between; align-items:baseline; gap:10px; padding:5px 0; border-bottom:1px dashed #e3e8ef; font-size:.8rem; color:#6b7686; }
    .ws-kv:last-child { border-bottom:0; }
    .ws-kv b { font-size:.9rem; color:#14213d; font-weight:700; white-space:nowrap; }

    /* tanker: slim strip of figures + compact compartment rows */
    .ws-strip { display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; }
    .ws-strip .ws-stat { padding:6px 10px; border-radius:8px; }
    .ws-strip .ws-stat .k { font-size:.65rem; }
    .ws-strip .ws-stat .v { font-size:.88rem; }
    @media (max-width: 575.98px) { .ws-strip { grid-template-columns:repeat(2, 1fr); } }
    .ws-modal .ws-truck { padding:4px 8px; border-radius:10px; }
    .ws-modal .ws-truck svg { max-height:150px; }
    .ws-modal .ws-cmp { padding:5px 10px; gap:8px; font-size:.78rem; grid-template-columns:30px minmax(80px,1fr) minmax(60px,1.4fr) auto auto; border-radius:8px; }
    .ws-modal .ws-cmp + .ws-cmp { margin-top:4px; }
    .ws-modal .ws-cmp .wsu-meter { height:5px; }

    /* compact actions */
    .ws-acts { display:inline-flex; gap:4px; padding:3px; background:#f1f4f8; border-radius:99px; }
    .ws-acts .mm-tab { border:0; background:transparent; color:#6b7686; font-size:.75rem; font-weight:600; padding:.2rem .8rem; border-radius:99px; }
    .ws-acts .mm-tab.active { background:#fff; color:#14213d; box-shadow:0 1px 3px rgba(20,33,61,.15); }
    .ws-acts .mm-tab[data-pane=contam].active { color:#b4232f; }
    .ws-modal .mm-pane label { font-size:.7rem; color:#6b7686; margin-bottom:2px; }
    .ws-modal .mm-pane .form-control, .ws-modal .mm-pane .form-select { font-size:.82rem; padding:.3rem .55rem; }
    .ws-modal .mm-pane .btn { font-size:.8rem; padding:.34rem .9rem; white-space:nowrap; }
    .ws-modal .mm-target { font-size:.78rem; color:#6b7686; font-weight:500; }
    .ws-modal .mm-bad { font-size:.75rem; padding:.3rem .6rem; margin-bottom:8px; }
    .ws-modal .ee-row .ee-no { font-size:.75rem; min-width:34px; justify-content:center; }
    .ws-modal .mm-pane .btn.ee-del { padding:.2rem .55rem; }
    .ws-modal .ee-total { font-size:.72rem; color:#6b7686; }
</style>
@endonce

<div class="modal fade ws-modal" id="unit-{{ $u->id }}" tabindex="-1" aria-hidden="true" data-solo="{{ $solo ? 1 : 0 }}"{!! $soloData !!}>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable {{ $u->tanker ? 'ws-wide' : '' }}">
        <div class="modal-content border-0 shadow-lg rounded-4">

            <div class="modal-header border-0 align-items-start">
                <div class="d-flex align-items-center gap-2">
                    <span class="ws-ico"><i class="bi {{ $u->tanker ? 'bi-truck' : 'bi-box-seam' }}"></i></span>
                    <div>
                        <h5 class="modal-title fw-bold mb-1">{{ $u->name }}</h5>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <span class="badge bg-light text-dark border">{{ $u->tanker ? 'Tanker truck' : 'Depot tank' }}</span>
                            @if (!$u->tanker)
                                @if ($u->product)
                                    <span class="badge text-white" style="background:{{ $col }};">{{ $u->product_name }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary border">No product set</span>
                                @endif
                            @endif
                            <span class="badge {{ $bb }}">{{ $bl }}</span>
                            @if ($u->bad)
                                <span class="badge {{ $u->fully_bad ? 'bg-danger' : 'bg-warning text-dark' }}">⚠ {{ number_format($u->bad) }} L contaminated</span>
                            @endif
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
              <div class="ws-view">

                {{-- ================= DEPOT: tank visual + figures ================= --}}
                @if ($solo)
                    @php $f = $u->pct / 100; @endphp
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <svg class="ws-tank" viewBox="0 0 150 190" role="img" aria-label="{{ $u->name }} is {{ $u->pct }}% full">
                            <defs>
                                <linearGradient id="st-{{ $u->id }}" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0" stop-color="#8793a3"/><stop offset=".3" stop-color="#d4dce5"/><stop offset=".5" stop-color="#f3f6f9"/><stop offset=".78" stop-color="#c3ccd7"/><stop offset="1" stop-color="#7c8898"/>
                                </linearGradient>
                                <clipPath id="gl-{{ $u->id }}"><rect x="32" y="44" width="8" height="82" rx="4"/></clipPath>
                            </defs>

                            {{-- ground, legs and bracing (the stand) --}}
                            <rect x="8" y="179" width="134" height="2" fill="#cbd5e1"/>
                            @foreach ([28, 46, 82, 100] as $lx)
                                <rect x="{{ $lx }}" y="138" width="5" height="38" fill="#64748b"/>
                                <rect x="{{ $lx - 3 }}" y="176" width="11" height="3" rx="1" fill="#475569"/>
                            @endforeach
                            <g stroke="#7b8798" stroke-width="1.2">
                                <line x1="33" y1="146" x2="46" y2="170"/><line x1="46" y1="146" x2="33" y2="170"/>
                                <line x1="87" y1="146" x2="100" y2="170"/><line x1="100" y1="146" x2="87" y2="170"/>
                            </g>

                            {{-- ladder --}}
                            <g stroke="#6b7686" stroke-width="1.3">
                                <line x1="112" y1="40" x2="112" y2="176"/><line x1="119" y1="40" x2="119" y2="176"/>
                                @for ($ly = 46; $ly <= 172; $ly += 7)
                                    <line x1="112" y1="{{ $ly }}" x2="119" y2="{{ $ly }}" stroke-width="1"/>
                                @endfor
                            </g>

                            {{-- outlet pipe + valve --}}
                            <rect x="63" y="138" width="5" height="30" fill="#7b8798"/>
                            <rect x="63" y="165" width="62" height="5" rx="1.5" fill="#7b8798"/>
                            <circle cx="96" cy="167.5" r="5" fill="none" stroke="#d62839" stroke-width="2"/>
                            <line x1="96" y1="160" x2="96" y2="163" stroke="#d62839" stroke-width="2"/>

                            {{-- shell: roof, body, seams, bottom ring --}}
                            <rect x="60" y="8" width="10" height="7" rx="2" fill="#7b8798"/>
                            <rect x="63" y="5" width="4" height="4" rx="1" fill="#94a3b8"/>
                            <path d="M21 36 L65 14 L109 36 Z" fill="url(#st-{{ $u->id }})" stroke="#7b8798" stroke-width="1.2" stroke-linejoin="round"/>
                            <rect x="24" y="34" width="82" height="102" fill="url(#st-{{ $u->id }})" stroke="{{ $u->bad ? '#d62839' : '#7b8798' }}" stroke-width="{{ $u->bad ? 2 : 1.2 }}"/>
                            <rect x="21" y="34" width="88" height="4" rx="1.5" fill="#8b97a8"/>
                            <g stroke="#7b8798" stroke-width=".8" opacity=".5">
                                <line x1="24" y1="60" x2="106" y2="60"/><line x1="24" y1="86" x2="106" y2="86"/><line x1="24" y1="112" x2="106" y2="112"/>
                            </g>
                            <rect x="22" y="134" width="86" height="5" rx="1.5" fill="#6b7686"/>
                            @if ($u->bad)
                                <rect x="25" y="118" width="80" height="16" fill="#d62839" opacity=".16"/>
                            @endif

                            {{-- level gauge on the shell --}}
                            <rect x="30" y="42" width="12" height="86" rx="6" fill="#374151"/>
                            <rect x="32" y="44" width="8" height="82" rx="4" fill="#eef2f6"/>
                            <g clip-path="url(#gl-{{ $u->id }})">
                                <rect x="32" y="{{ 44 + 82 * (1 - $f) }}" width="8" height="{{ 82 * $f }}" fill="{{ $col }}" opacity="{{ $u->bad ? .55 : .95 }}"/>
                            </g>
                            @foreach ([75, 50, 25] as $t)
                                <line x1="42" x2="46" y1="{{ 44 + 82 * (1 - $t / 100) }}" y2="{{ 44 + 82 * (1 - $t / 100) }}" stroke="#4b5563" stroke-width="1"/>
                                <text x="48" y="{{ 44 + 82 * (1 - $t / 100) + 2.5 }}" font-size="6.5" fill="#374151">{{ $t }}</text>
                            @endforeach

                            {{-- readout plate --}}
                            <text x="80" y="84" font-size="19" font-weight="700" text-anchor="middle" fill="#14213d">{{ $u->pct }}%</text>
                            <text x="80" y="98" font-size="8" font-weight="600" text-anchor="middle" fill="#334155">{{ number_format($u->stock) }} L</text>
                            <text x="80" y="108" font-size="6.5" text-anchor="middle" fill="#5b6778">of {{ number_format($u->cap) }}</text>
                            @if ($u->bad)
                                <text x="80" y="129" font-size="9" text-anchor="middle">⚠</text>
                            @endif
                        </svg>

                        <div class="flex-grow-1">
                            <div class="ws-kv"><span>In stock</span><b>{{ number_format($u->stock) }} L</b></div>
                            <div class="ws-kv"><span>Sellable</span><b class="{{ $u->bad ? 'text-danger' : 'text-success' }}">{{ number_format($u->sellable) }} L</b></div>
                            <div class="ws-kv"><span>On hold for delivery</span><b style="color:#a16207;">{{ number_format($u->hold) }} L</b></div>
                            <div class="ws-kv"><span>Room left</span><b>{{ number_format($room) }} L</b></div>
                            <div class="ws-kv"><span>Capacity</span><b>{{ number_format($u->cap) }} L</b></div>
                        </div>
                    </div>

                    @if ($u->note)
                        <div class="small text-muted mb-2"><i class="bi bi-chat-left-text me-1"></i>{{ $u->note }}</div>
                    @endif
                @endif

                {{-- ================= TANKER: figures + truck + compartments ================= --}}
                @if ($u->tanker)
                    <div class="ws-strip mb-2">
                        <div class="ws-stat"><div class="k">In stock</div><div class="v">{{ number_format($u->stock) }} L</div></div>
                        <div class="ws-stat"><div class="k">Sellable</div><div class="v {{ $u->bad ? 'text-danger' : 'text-success' }}">{{ number_format($u->sellable) }} L</div></div>
                        <div class="ws-stat"><div class="k">On hold</div><div class="v" style="color:#a16207;">{{ number_format($u->hold) }} L</div></div>
                        <div class="ws-stat"><div class="k">Room left</div><div class="v">{{ number_format($room) }} L</div></div>
                    </div>
                    <div class="d-flex justify-content-between wsu-sub mb-1">
                        <span>{{ $u->pct }}% full</span><span>{{ number_format($u->cap) }} L capacity</span>
                    </div>
                    <div class="wsu-meter mb-3" style="height:7px;"><span style="width:{{ $u->pct }}%;background:#64748b;"></span></div>

                    @if ($n === 0)
                        <div class="alert alert-warning small py-2 mb-0">
                            This truck has no compartments yet.
                            @if ($canEdit)Use the <strong>Edit</strong> tab below to add them.@endif
                        </div>
                    @else
                        <div class="ws-truck">
                            <svg viewBox="0 0 430 128" style="width:100%;height:auto;max-height:150px;display:block;" role="img" aria-label="{{ $u->name }} compartments">
                                @php $cw = 298 / $n; @endphp
                                <defs>
                                    <clipPath id="tt-{{ $u->id }}"><rect x="122" y="16" width="298" height="72" rx="24"/></clipPath>
                                    <linearGradient id="ts-{{ $u->id }}" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0" stop-color="#fff" stop-opacity=".6"/><stop offset=".3" stop-color="#fff" stop-opacity=".1"/><stop offset=".7" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".28"/>
                                    </linearGradient>
                                    <linearGradient id="tc-{{ $u->id }}" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0" stop-color="#fff" stop-opacity=".3"/><stop offset=".5" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".22"/>
                                    </linearGradient>
                                </defs>

                                <rect x="0" y="118" width="430" height="1.5" fill="#cbd5e1"/>

                                {{-- frame, fifth wheel, landing gear --}}
                                <rect x="96" y="90" width="328" height="6" rx="2" fill="#3b4252"/>
                                <rect x="100" y="86" width="30" height="5" rx="2" fill="#2d3748"/>
                                <rect x="146" y="96" width="4" height="20" fill="#64748b"/>
                                <rect x="141" y="114" width="14" height="3" rx="1" fill="#475569"/>

                                {{-- tank shell (cut-away: liquid per compartment) --}}
                                <g clip-path="url(#tt-{{ $u->id }})">
                                    <rect x="122" y="16" width="298" height="72" fill="#e3e8ee"/>
                                    @foreach ($u->comps as $c)
                                        @php
                                            $x = 122 + $loop->index * $cw;
                                            $fc = $c->cap ? min(1, $c->level / $c->cap) : 0;
                                            $cc = $pc[$c->product] ?? '#9aa5b4';
                                        @endphp
                                        <rect x="{{ $x }}" y="{{ 16 + 72 * (1 - $fc) }}" width="{{ $cw }}" height="{{ 72 * $fc }}" fill="{{ $cc }}" opacity="{{ $c->bad ? .5 : .92 }}"/>
                                        @if ($fc > 0 && $fc < 1)
                                            <rect x="{{ $x }}" y="{{ 16 + 72 * (1 - $fc) }}" width="{{ $cw }}" height="2" fill="#fff" opacity=".4"/>
                                        @endif
                                        @if ($loop->index > 0)
                                            <rect x="{{ $x - 2 }}" y="16" width="4" height="72" fill="#8b97a8"/>
                                            <rect x="{{ $x - 2 }}" y="16" width="1" height="72" fill="#fff" opacity=".5"/>
                                        @endif
                                    @endforeach
                                    <rect x="122" y="80" width="298" height="4" fill="#64748b" opacity=".5"/>
                                    <rect x="122" y="16" width="298" height="72" fill="url(#ts-{{ $u->id }})"/>
                                </g>
                                <rect x="122" y="16" width="298" height="72" rx="24" fill="none" stroke="#5b6778" stroke-width="1.5"/>

                                {{-- manholes + outlet valves --}}
                                @foreach ($u->comps as $c)
                                    @php $mid = 122 + $loop->index * $cw + $cw / 2; @endphp
                                    <rect x="{{ $mid - 9 }}" y="10" width="18" height="7" rx="3" fill="#7b8798" stroke="#5b6778" stroke-width=".8"/>
                                    <rect x="{{ $mid - 4 }}" y="7" width="8" height="4" rx="1.5" fill="#94a3b8"/>
                                    <rect x="{{ $mid - 3 }}" y="88" width="6" height="4" fill="#475569"/>
                                @endforeach

                                {{-- rear ladder, bumper, mud flap --}}
                                <g stroke="#475569" stroke-width="1.3">
                                    <line x1="423" y1="30" x2="423" y2="92"/><line x1="428" y1="30" x2="428" y2="92"/>
                                    @for ($ly = 36; $ly <= 88; $ly += 8)
                                        <line x1="423" y1="{{ $ly }}" x2="428" y2="{{ $ly }}" stroke-width="1"/>
                                    @endfor
                                </g>
                                <rect x="420" y="96" width="8" height="5" rx="1.5" fill="#475569"/>
                                <rect x="416" y="98" width="3" height="14" fill="#1f2937"/>

                                {{-- tractor cab --}}
                                <path d="M40 26 L52 10 H92 V26 Z" fill="#2f5d8c"/>
                                <rect x="90" y="8" width="4" height="52" rx="2" fill="#6b7686"/>
                                <path d="M6 92 V36 Q6 24 18 24 H80 Q88 24 90 32 V92 Z" fill="#3b6ea5"/>
                                <path d="M6 92 V36 Q6 24 18 24 H80 Q88 24 90 32 V92 Z" fill="url(#tc-{{ $u->id }})"/>
                                <rect x="6" y="72" width="84" height="6" fill="#fff" opacity=".85"/>
                                <rect x="6" y="80" width="84" height="2" fill="#f59e0b"/>
                                <path d="M14 32 H56 V58 H14 Q10 58 10 54 V36 Q10 32 14 32 Z" fill="#bcd6ee" stroke="#2f4f75" stroke-width="1.2"/>
                                <path d="M20 32 L32 32 L22 58 L14 58 Z" fill="#fff" opacity=".35"/>
                                <rect x="10" y="28" width="64" height="62" rx="4" fill="none" stroke="#2f4f75" stroke-width=".9" opacity=".7"/>
                                <rect x="62" y="62" width="8" height="3" rx="1.5" fill="#cbd5e1"/>
                                <rect x="2" y="34" width="5" height="16" rx="2" fill="#1f2937"/>
                                <rect x="4" y="62" width="4" height="8" rx="1.5" fill="#fde68a"/>
                                <rect x="1" y="86" width="22" height="8" rx="3" fill="#475569"/>
                                <rect x="24" y="92" width="22" height="3" rx="1" fill="#334155"/>
                                <path d="M15 105 A15 15 0 0 1 45 105" fill="none" stroke="#2f4f75" stroke-width="3"/>

                                {{-- wheels --}}
                                @foreach ([30, 78, 102, 352, 376, 400] as $wx)
                                    <circle cx="{{ $wx }}" cy="105" r="13" fill="#1f2937"/>
                                    <circle cx="{{ $wx }}" cy="105" r="7.5" fill="#cbd5e1"/>
                                    <circle cx="{{ $wx }}" cy="105" r="3" fill="#64748b"/>
                                @endforeach

                                {{-- compartment labels + click targets --}}
                                @foreach ($u->comps as $c)
                                    @php
                                        $x = 122 + $loop->index * $cw;
                                        $mid = $x + $cw / 2;
                                    @endphp
                                    <g class="cmp-g js-cmp" role="button" tabindex="0" aria-label="Compartment {{ $c->no }}"{!! $kv(['cid' => $c->id, 'no' => $c->no, 'product' => $c->product, 'level' => $c->level, 'cap' => $c->cap, 'bad' => $c->bad, 'note' => $c->note, 'unassigned' => $u->unassigned]) !!}>
                                        <text x="{{ $mid }}" y="33" font-size="8.5" font-weight="700" text-anchor="middle" fill="#14213d" stroke="#fff" stroke-width="2.4" paint-order="stroke">C{{ $c->no }}</text>
                                        <text x="{{ $mid }}" y="52" font-size="13" font-weight="700" text-anchor="middle" fill="#14213d" stroke="#fff" stroke-width="2.6" paint-order="stroke">{{ $c->product ?: '–' }}</text>
                                        @if ($c->level > 0)
                                            <text x="{{ $mid }}" y="65" font-size="7" font-weight="600" text-anchor="middle" fill="#14213d" stroke="#fff" stroke-width="2.2" paint-order="stroke">{{ number_format($c->level) }}</text>
                                        @endif
                                        @if ($c->bad)
                                            <text x="{{ $mid }}" y="78" font-size="9" text-anchor="middle">⚠</text>
                                        @endif
                                        <rect class="cmp-shell" x="{{ $x }}" y="17" width="{{ $cw }}" height="70" rx="6" fill="transparent" stroke="{{ $c->bad ? '#d62839' : 'transparent' }}" stroke-width="{{ $c->bad ? 2.5 : 0 }}"/>
                                    </g>
                                @endforeach
                            </svg>
                        </div>

                        <div class="mt-2">
                            @foreach ($u->comps as $c)
                                @php $cc = $pc[$c->product] ?? '#9aa5b4'; @endphp
                                <div class="ws-cmp js-cmp" role="button" tabindex="0"{!! $kv(['cid' => $c->id, 'no' => $c->no, 'product' => $c->product, 'level' => $c->level, 'cap' => $c->cap, 'bad' => $c->bad, 'note' => $c->note, 'unassigned' => $u->unassigned]) !!}>
                                    <span class="ws-cmp-no">C{{ $c->no }}</span>
                                    <span><span class="wsu-dot" style="background:{{ $cc }};"></span>{{ $c->product ? ($products[$c->product] ?? $c->product) : 'No product' }}</span>
                                    <span class="ws-cmp-bar"><span class="wsu-meter d-block"><span style="width:{{ $pctOf($c->level, $c->cap) }}%;background:{{ $cc }};"></span></span></span>
                                    <span class="ws-cmp-l">{{ number_format($c->level) }} / {{ number_format($c->cap) }} L</span>
                                    @if ($c->bad)<span class="badge bg-danger">⚠</span>@else<span></span>@endif
                                </div>
                            @endforeach
                        </div>

                        @if ($u->unassigned > 0)
                            <div class="alert alert-warning small py-2 mt-2 mb-0">
                                {{ number_format($u->unassigned) }} L in this truck isn't placed in a compartment yet.
                                @if ($canEdit)Pick a compartment, open <strong>Add</strong>, and choose <strong>Unassigned stock already in the truck</strong>.@endif
                            </div>
                        @endif
                    @endif
                @endif

              </div>{{-- /ws-view --}}

                {{-- ================= actions ================= --}}
                @if ($canEdit)
                    <hr class="my-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        @if ($canAct)<div class="mm-target"></div>@else<div></div>@endif
                        <div class="ws-acts" role="group">
                            @if ($canAct)
                                <button type="button" class="mm-tab active" data-pane="add">Add</button>
                                <button type="button" class="mm-tab" data-pane="reduce">Reduce</button>
                                <button type="button" class="mm-tab" data-pane="contam">Contamination</button>
                            @endif
                            <button type="button" class="mm-tab {{ $canAct ? '' : 'active' }}" data-pane="edit"><i class="bi bi-pencil me-1"></i>Edit</button>
                        </div>
                    </div>
                @endif

                @if ($canAct)
                    <div class="alert alert-danger mm-bad d-none"></div>

                    {{-- ADD --}}
                    <form method="POST" action="{{ route('wetstock.tank-actions.add', $u->id) }}" class="mm-form mm-pane" data-pane="add">
                        @csrf
                        <input type="hidden" name="compartment_id">
                        <div class="mb-2 mm-src d-none">
                            <label>Where is the stock coming from?</label>
                            <select name="source" class="form-select">
                                <option value="new">New stock (Stock IN)</option>
                                <option value="legacy">Unassigned stock already in the truck</option>
                            </select>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col mm-prod">
                                <label>Product</label>
                                <select name="product_type" class="form-select">
                                    @foreach ($products as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col">
                                <label>Liters <span class="mm-room text-muted"></span></label>
                                <input type="number" name="quantity" class="form-control" min="1" required>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary-custom mm-submit-add">Add stock</button>
                            </div>
                        </div>
                    </form>

                    {{-- REDUCE --}}
                    <form method="POST" action="{{ route('wetstock.tank-actions.reduce', $u->id) }}" class="mm-form mm-pane d-none" data-pane="reduce">
                        @csrf
                        <input type="hidden" name="compartment_id">
                        <div class="row g-2 align-items-end">
                            <div class="col">
                                <label>Liters to remove <span class="mm-have text-muted"></span></label>
                                <input type="number" name="quantity" class="form-control" min="1" required>
                            </div>
                            <div class="col">
                                <label>Reason</label>
                                <select name="reason" class="form-select">
                                    @foreach (\App\Http\Controllers\WetStock\TankActionController::REASONS as $r)<option>{{ $r }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary-custom">Remove stock</button>
                            </div>
                        </div>
                    </form>

                    {{-- CONTAMINATION --}}
                    <form method="POST" action="{{ route('wetstock.tank-actions.contamination', $u->id) }}" class="mm-form mm-pane d-none" data-pane="contam">
                        @csrf
                        <input type="hidden" name="compartment_id">
                        <p class="small text-muted mb-2 mm-contam-help" style="font-size:.75rem;"></p>
                        <div class="row g-2 align-items-end">
                            <div class="col-4 mm-liters">
                                <label>Contaminated liters</label>
                                <input type="number" name="liters" class="form-control" min="1">
                            </div>
                            <div class="col mm-note">
                                <label>What was found?</label>
                                <input type="text" name="remarks" class="form-control" placeholder="e.g. Water in sample">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-danger mm-contam-btn"></button>
                            </div>
                        </div>
                    </form>
                @endif

                @if ($canEdit)
                    {{-- EDIT (same route + field names as the old Edit Tank page) --}}
                    <form method="POST" action="{{ route('wetstock.tanks.update', $u->id) }}" class="mm-form mm-pane ws-edit-form {{ $canAct ? 'd-none' : '' }}" data-pane="edit" novalidate>
                        @csrf
                        <input type="hidden" name="category" value="{{ $u->tanker ? 'tanker' : 'depot' }}">
                        <div class="row g-2 mb-2">
                            <div class="col-12 {{ $solo ? 'col-md-5' : '' }}">
                                <label>{{ $u->tanker ? 'Tanker identifier' : 'Tank identifier' }}</label>
                                <input type="text" name="name" class="form-control" value="{{ $u->name }}" required>
                            </div>
                            @if ($solo)
                                <div class="col-7 col-md-4">
                                    <label>Product stored</label>
                                    <select name="product_type" class="form-select">
                                        <option value="">-- Select product --</option>
                                        @foreach ($products as $code => $label)
                                            <option value="{{ $code }}" {{ $u->product === $code ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-5 col-md-3">
                                    <label>Max capacity (L)</label>
                                    <input type="number" name="max_capacity" class="form-control" min="1" value="{{ $u->cap }}">
                                </div>
                            @endif
                        </div>

                        @if ($u->tanker)
                            @php
                                $eRows = $u->comps->map(fn ($c) => ['id' => $c->id, 'capacity' => $c->cap, 'product_type' => $c->product])->all();
                                if (empty($eRows)) { $eRows = [['id' => '', 'capacity' => '', 'product_type' => '']]; }
                            @endphp
                            <label>Compartments</label>
                            <div class="ee-rows d-flex flex-column gap-1">
                                @foreach ($eRows as $i => $r)
                                    <div class="input-group input-group-sm ee-row">
                                        <span class="input-group-text ee-no fw-semibold">C{{ $loop->iteration }}</span>
                                        <input type="hidden" name="compartments[{{ $i }}][id]" value="{{ $r['id'] }}">
                                        <input type="number" name="compartments[{{ $i }}][capacity]" class="form-control ee-cap" min="1" placeholder="Liters" value="{{ $r['capacity'] }}">
                                        <select name="compartments[{{ $i }}][product_type]" class="form-select">
                                            <option value="">Any / empty</option>
                                            @foreach ($products as $code => $label)
                                                <option value="{{ $code }}" {{ ($r['product_type'] ?? '') === $code ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" class="btn btn-outline-danger ee-del" title="Remove"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                @endforeach
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1 mb-2">
                                <button type="button" class="btn btn-sm btn-outline-primary ee-add"><i class="bi bi-plus-lg me-1"></i>Add compartment</button>
                                <span class="ee-total">Total capacity: <strong class="ee-sum">0</strong> L</span>
                            </div>
                            <template class="ee-tpl">
                                <div class="input-group input-group-sm ee-row">
                                    <span class="input-group-text ee-no fw-semibold"></span>
                                    <input type="hidden" name="compartments[__i__][id]" value="">
                                    <input type="number" name="compartments[__i__][capacity]" class="form-control ee-cap" min="1" placeholder="Liters">
                                    <select name="compartments[__i__][product_type]" class="form-select">
                                        <option value="">Any / empty</option>
                                        @foreach ($products as $code => $label)
                                            <option value="{{ $code }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="btn btn-outline-danger ee-del" title="Remove"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </template>
                        @endif

                        <div class="row g-2 align-items-end">
                            <div class="col">
                                <label>Remarks (optional)</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Add optional notes..." value="{{ $u->note }}">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary-custom">Save changes</button>
                            </div>
                        </div>
                    </form>
                @endif
            </div>

            <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>