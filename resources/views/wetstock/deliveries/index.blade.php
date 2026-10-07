@extends('layouts.app')

@section('title', 'Assign Deliveries & Fulfillment')

@section('content')
<style>
    .wetstock-deliveries-table {
        table-layout: auto;
        width: 100%;
    }
    .wetstock-deliveries-table thead th {
        font-size: 0.74rem;
        font-weight: 600;
        color: #6b7280;
        white-space: nowrap;
        border-bottom: 1px solid #e9ecef;
    }
    .wetstock-deliveries-table tbody td {
        font-size: 0.8rem;
        vertical-align: middle;
    }
    .wetstock-deliveries-table th,
    .wetstock-deliveries-table td {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
        padding-left: 0.45rem;
        padding-right: 0.45rem;
    }
    .wetstock-deliveries-table tbody tr {
        border-bottom: 1px solid #f1f3f5;
    }
    .wetstock-deliveries-table tbody tr:hover {
        background-color: #fafbfc;
    }
    .wetstock-deliveries-table .client-cell {
        max-width: 170px;
        min-width: 130px;
    }
    .wetstock-deliveries-table .client-name {
        font-size: 0.76rem;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: normal;
        word-break: break-word;
    }
    .wetstock-type-badge {
        font-size: 0.66rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        padding: 0.26rem 0.5rem;
        white-space: nowrap;
    }
    .wetstock-activity-cell {
        font-size: 0.74rem;
        line-height: 1.5;
        max-width: 110px;
    }
    .compartment-line {
        font-size: 0.72rem;
        line-height: 1.4;
        font-weight: 400;
    }

    /* Allocation pop-up */
    .alloc-modal .alloc-stat {
        background: #f8f9fa;
        border-radius: 0.6rem;
        padding: 0.6rem 0.8rem;
    }
    .alloc-modal .alloc-stat .label {
        font-size: 0.68rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #6b7280;
        font-weight: 600;
    }
    .alloc-modal .alloc-stat .value {
        font-size: 1.05rem;
        font-weight: 700;
        color: #111827;
    }
    .alloc-modal .alloc-line {
        border: 1px solid #e5e7eb;
        border-radius: 0.8rem;
        padding: 0.9rem 1rem;
        background: #fff;
    }
    .alloc-modal .alloc-line.done {
        background: #f0fdf4;
        border-color: #bbf7d0;
    }
    .alloc-modal .alloc-existing {
        font-size: 0.78rem;
    }
</style>
<div class="row justify-content-center">
    <div class="col-12">
        <div class="mb-4">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="{{ route('wetstock.dashboard') }}" class="text-decoration-none text-secondary">Wet Stock</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Assign Deliveries</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-truck text-primary me-2"></i>Delivery Allocations and Fulfillment
            </h3>
            <p class="text-muted small mb-3">Allocate sales deliveries to tanks (hold stock) and mark as fulfilled (dispatched fuel). Each product/compartment of a DR is allocated separately.</p>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                {{-- Search by DR Number or ATL Number — applies to whichever tab is active --}}
                <form method="GET" action="{{ route('wetstock.deliveries.index') }}" class="mb-0 flex-grow-1" style="max-width: 600px;">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <div class="d-flex align-items-center bg-white border shadow-sm rounded-pill px-3 py-1">
                        <i class="bi bi-search text-muted me-2"></i>
                        <input type="text" name="search" value="{{ $search ?? '' }}" class="form-control form-control-sm border-0 shadow-none p-0" placeholder="Search DR # or ATL #...">
                        @if (!empty($search))
                            <a href="{{ route('wetstock.deliveries.index', ['tab' => $activeTab]) }}" class="text-muted mx-2" title="Clear search">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        @endif
                        <button type="submit" class="btn btn-primary-custom btn-sm rounded-pill px-3 py-1 ms-2">Search</button>
                    </div>
                </form>

                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-light border shadow-sm rounded-pill px-3 py-1 d-flex align-items-center flex-shrink-0">
                    <i class="bi bi-arrow-left-circle me-2 text-primary"></i><span class="fw-medium text-dark small">Back to Dashboard</span>
                </a>
            </div>
        </div>

        <!-- 3 Nav Tabs -->
        <ul class="nav nav-tabs nav-fill border-bottom mb-4" id="assignTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'unassigned' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'unassigned', 'search' => $search ?? null]) }}">
                    <i class="bi bi-inbox text-warning"></i>
                    <span>Unassigned Deliveries</span>
                    @if ($unassignedCount > 0)
                        <span class="badge bg-warning text-dark rounded-pill" style="font-size: 0.7rem;">{{ $unassignedCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'assigned' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'assigned', 'search' => $search ?? null]) }}">
                    <i class="bi bi-check2-circle text-primary"></i>
                    <span>Assigned Deliveries</span>
                    @if ($assignedCount > 0)
                        <span class="badge bg-primary text-white rounded-pill" style="font-size: 0.7rem;">{{ $assignedCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link d-flex align-items-center justify-content-center gap-2 py-2 small fw-medium {{ $activeTab === 'history' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'search' => $search ?? null]) }}">
                    <i class="bi bi-clock-history text-success"></i>
                    <span>Fulfillment History</span>
                    @if ($historyCount > 0)
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill" style="font-size: 0.7rem;">{{ $historyCount }}</span>
                    @endif
                </a>
            </li>
        </ul>

        {{-- ==================== TAB 1: UNASSIGNED ==================== --}}
        @if ($activeTab === 'unassigned')
            <div class="card card-custom p-3 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($unassignedDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle display-4 text-success mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">All Deliveries Allocated!</h5>
                            <p class="text-muted">There are currently no deliveries awaiting tank assignment.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 wetstock-deliveries-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR# <i class="bi bi-arrow-down text-muted" title="Sorted highest → lowest"></i></th>
                                        <th class="py-3">ATL#</th>
                                        <th class="py-3">Client</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Delivery Date</th>
                                        <th class="py-3 pe-3 text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($unassignedDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-semibold text-dark">
                                                {{ $delivery->dr_number }}
                                                @if ($delivery->allocations->isNotEmpty())
                                                    <span class="badge bg-warning-subtle border border-warning-subtle rounded-pill ms-1" style="color:#a16207; font-size:0.62rem;">PARTIAL</span>
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if (!empty($delivery->atl_number))
                                                    {{ $delivery->atl_number }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="client-cell" title="{{ $delivery->order->account ?? '-' }}">
                                                <div class="client-name">{{ $delivery->order->account ?? '-' }}</div>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill wetstock-type-badge">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill wetstock-type-badge">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill wetstock-type-badge">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="text-muted small">
                                                {{ $delivery->delivery_date ? $delivery->delivery_date->format('M d, Y') : '-' }}
                                            </td>
                                            <td class="pe-3 text-end">
                                                <button type="button" class="btn btn-sm btn-primary-custom rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#allocModal{{ $delivery->id }}">
                                                    <i class="bi bi-diagram-3 me-1"></i> {{ Auth::user()->canEditModule2() ? 'Assign Tanks' : 'View' }}
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            {{ $unassignedDeliveries->appends(['tab' => 'unassigned', 'search' => $search ?? null])->links() }}
                        </div>
                    @endif
                </div>
            </div>

            {{-- Allocation pop-ups (outside the table to avoid layout glitches) --}}
            @foreach ($unassignedDeliveries as $delivery)
                @php
                    $siteWarehouse = $warehouses->firstWhere('name', $delivery->order->location ?? null);
                    $depotTanks = $siteWarehouse ? $siteWarehouse->activeTanks->where('category', 'depot') : collect();
                    $tankerTanks = $siteWarehouse ? $siteWarehouse->activeTanks->where('category', 'tanker') : collect();
                    $otherTanks = $siteWarehouse ? $siteWarehouse->activeTanks->whereNotIn('category', ['depot', 'tanker']) : collect();
                    $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first();

                    // Lines to allocate: real compartments, or one stand-in line for a legacy DR without any
                    $lines = $delivery->items->isNotEmpty()
                        ? $delivery->items->map(fn ($i) => [
                            'id' => $i->id,
                            'no' => $i->compartment_no ?: $loop->iteration,
                            'code' => $i->product_type ?: '-',
                            'name' => $i->product_name,
                            'qty' => (int) $i->qty_out,
                            'allocated' => $i->allocated_quantity,
                            'remaining' => $i->remaining_to_allocate,
                        ])->values()
                        : collect([[
                            'id' => null,
                            'no' => 1,
                            'code' => $delivery->product_type ?: '-',
                            'name' => $delivery->product_name,
                            'qty' => (int) $delivery->qty_out,
                            'allocated' => $delivery->allocated_quantity,
                            'remaining' => $delivery->remaining_to_allocate,
                        ]]);
                @endphp
                <div class="modal fade alloc-modal" id="allocModal{{ $delivery->id }}" tabindex="-1" aria-labelledby="allocModalLabel{{ $delivery->id }}" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <div class="modal-header border-bottom py-3 bg-light">
                                <div>
                                    <h5 class="modal-title fw-bold text-dark mb-1" id="allocModalLabel{{ $delivery->id }}">
                                        <i class="bi bi-truck text-primary me-2"></i>DR# {{ $delivery->dr_number }}
                                        @if (!empty($delivery->atl_number))
                                            <span class="text-muted fw-normal small ms-2">ATL# {{ $delivery->atl_number }}</span>
                                        @endif
                                    </h5>
                                    <div class="small text-muted">
                                        {{ $delivery->order->account ?? '-' }}
                                        &middot; {{ $delivery->type }}
                                        &middot; {{ $delivery->delivery_date ? $delivery->delivery_date->format('M d, Y') : '-' }}
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                {{-- Summary --}}
                                <div class="row g-2 mb-3">
                                    <div class="col-6 col-md-3">
                                        <div class="alloc-stat"><div class="label">Total Qty Out</div><div class="value">{{ number_format($delivery->qty_out) }} L</div></div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="alloc-stat"><div class="label">Allocated</div><div class="value text-primary">{{ number_format($delivery->allocated_quantity) }} L</div></div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="alloc-stat"><div class="label">Remaining</div><div class="value text-warning-emphasis">{{ number_format($delivery->remaining_to_allocate) }} L</div></div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="alloc-stat"><div class="label">Site</div><div class="value" style="font-size:0.9rem;">{{ $delivery->order->location ?? 'No Site' }}</div></div>
                                    </div>
                                </div>

                                <div class="small text-muted mb-3">
                                    <i class="bi bi-person me-1"></i>Created by <span class="text-dark">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</span>
                                    @if ($latestApprovedMod)
                                        <span class="ms-2 text-warning"><i class="bi bi-pencil me-1"></i>Revised (requested by {{ $latestApprovedMod->requestedBy->name ?? '—' }})</span>
                                    @endif
                                </div>

                                @if (!$siteWarehouse)
                                    <div class="alert alert-warning small">
                                        No warehouse matches this order's site ({{ $delivery->order->location ?? '-' }}), so no tank or truck can be selected.
                                    </div>
                                @endif

                                {{-- One block per product / compartment --}}
                                <div class="d-flex flex-column gap-3">
                                    @foreach ($lines as $line)
                                        @php
                                            $lineAllocs = $line['id']
                                                ? $delivery->allocations->where('delivery_item_id', $line['id'])
                                                : $delivery->allocations;
                                            $usedTankIds = $lineAllocs->pluck('storage_tank_id')->all();
                                            $isDone = $line['remaining'] <= 0;
                                        @endphp
                                        <div class="alloc-line {{ $isDone ? 'done' : '' }}">
                                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                                <div>
                                                    <span class="badge bg-light text-secondary border me-1">#{{ $line['no'] }}</span>
                                                    <span class="fw-bold text-dark">{{ $line['code'] === '-' ? 'Unspecified' : $line['code'] . ' - ' . $line['name'] }}</span>
                                                    <span class="text-muted small ms-2">needs {{ number_format($line['qty']) }} L</span>
                                                </div>
                                                @if ($isDone)
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill"><i class="bi bi-check-circle me-1"></i>Fully allocated</span>
                                                @else
                                                    <span class="badge bg-warning-subtle border border-warning-subtle rounded-pill" style="color:#a16207;">{{ number_format($line['remaining']) }} L left to allocate</span>
                                                @endif
                                            </div>

                                            {{-- Already allocated tanks / trucks for this product --}}
                                            @if ($lineAllocs->isNotEmpty())
                                                <div class="d-flex flex-column gap-1 mb-2">
                                                    @foreach ($lineAllocs as $alloc)
                                                        <div class="d-flex align-items-center justify-content-between bg-light rounded px-2 py-1 border alloc-existing">
                                                            <div>
                                                                <i class="bi {{ ($alloc->tank->category ?? '') === 'tanker' ? 'bi-truck' : 'bi-fuel-pump' }} text-primary me-1"></i>
                                                                <strong>{{ $alloc->tank->name ?? '—' }}</strong>
                                                                <span class="text-muted">({{ $alloc->tank->warehouse->name ?? '—' }})</span>
                                                            </div>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="font-monospace fw-bold">{{ number_format($alloc->quantity) }} L</span>
                                                                @if (Auth::user()->canEditModule2())
                                                                    <form method="POST" action="{{ route('wetstock.deliveries.unassign', $alloc->id) }}" class="d-inline" onsubmit="return confirm('Remove {{ number_format($alloc->quantity) }}L allocation from {{ $alloc->tank->name }}?');">
                                                                        @csrf
                                                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Remove allocation"><i class="bi bi-x-circle"></i></button>
                                                                    </form>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            {{-- Allocate form --}}
                                            @if (!$isDone && Auth::user()->canEditModule2() && $siteWarehouse)
                                                <form method="POST" action="{{ route('wetstock.deliveries.allocate', $delivery->id) }}" class="alloc-form">
                                                    @csrf
                                                    @if ($line['id'])
                                                        <input type="hidden" name="delivery_item_id" value="{{ $line['id'] }}">
                                                    @endif
                                                    <div class="row g-2 align-items-end">
                                                        <div class="col-12 col-md-7">
                                                            <label class="form-label small text-secondary mb-1">Tank / Truck</label>
                                                            <select name="storage_tank_id" class="form-select form-select-sm alloc-tank" required>
                                                                <option value="">Select tank or truck...</option>
                                                                @foreach ([['Depot Tanks', $depotTanks], ['Tanker Trucks', $tankerTanks], ['Other', $otherTanks]] as [$groupLabel, $group])
                                                                    @if ($group->isNotEmpty())
                                                                        <optgroup label="{{ $groupLabel }}">
                                                                            @foreach ($group as $t)
                                                                                @php
                                                                                    $disabled = $t->isFullyContaminated() || $t->effective_available < 1 || in_array($t->id, $usedTankIds, true);
                                                                                @endphp
                                                                                <option value="{{ $t->id }}" data-available="{{ $t->effective_available }}" {{ $disabled ? 'disabled' : '' }}>
                                                                                    {{ $t->name }} — {{ number_format($t->effective_available) }} L avail
                                                                                    {{ in_array($t->id, $usedTankIds, true) ? ' [already used for this product]' : '' }}
                                                                                    {{ $t->hasContamination() ? ' [' . number_format($t->contaminated_liters) . 'L Contaminated' . ($t->isFullyContaminated() ? ' - BLOCKED' : '') . ']' : '' }}
                                                                                </option>
                                                                            @endforeach
                                                                        </optgroup>
                                                                    @endif
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div class="col-6 col-md-3">
                                                            <label class="form-label small text-secondary mb-1">Liters</label>
                                                            <input type="number" name="quantity" class="form-control form-control-sm font-monospace alloc-qty" min="1" max="{{ $line['remaining'] }}" value="{{ $line['remaining'] }}" data-remaining="{{ $line['remaining'] }}" required>
                                                        </div>
                                                        <div class="col-6 col-md-2">
                                                            <button type="submit" class="btn btn-sm btn-primary-custom w-100">Allocate</button>
                                                        </div>
                                                    </div>
                                                    <div class="small text-muted mt-1 alloc-hint"></div>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="modal-footer border-top bg-light py-2">
                                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

        {{-- ==================== TAB 2: ASSIGNED (PENDING FULFILLMENT) ==================== --}}
        @elseif ($activeTab === 'assigned')
            <div class="card card-custom p-3 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($assignedDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">No Deliveries Pending Fulfillment</h5>
                            <p class="text-muted">Allocate tanks in the Unassigned tab first. Once allocated, deliveries will appear here awaiting fulfillment.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 wetstock-deliveries-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR# <i class="bi bi-arrow-down text-muted" title="Sorted highest → lowest"></i></th>
                                        <th class="py-3">ATL#</th>
                                        <th class="py-3">Client</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Volume</th>
                                        <th class="py-3">Assigned Tanks Breakdown</th>
                                        <th class="py-3">Status</th>
                                        <th class="py-3">Activity</th>
                                        <th class="pe-3 py-3 text-end">Fulfillment Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($assignedDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-semibold text-dark">{{ $delivery->dr_number }}</td>
                                            <td class="small">
                                                @if (!empty($delivery->atl_number))
                                                    {{ $delivery->atl_number }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="client-cell" title="{{ $delivery->order->account ?? '-' }}">
                                                <div class="client-name">{{ $delivery->order->account ?? '-' }}</div>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill wetstock-type-badge">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill wetstock-type-badge">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill wetstock-type-badge">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="fw-bold text-dark font-monospace">
                                                {{ number_format($delivery->qty_out) }} L
                                                @if ($delivery->items->count() > 1)
                                                    <div class="text-muted compartment-line">{{ $delivery->items_summary }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    @foreach ($delivery->allocations as $alloc)
                                                        <div class="d-flex align-items-center justify-content-between bg-light rounded px-2 py-1 border small">
                                                            <div>
                                                                <i class="bi bi-fuel-pump text-primary me-1"></i>
                                                                @if ($alloc->item)
                                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ $alloc->item->product_type ?: '-' }}</span>
                                                                @endif
                                                                <strong>{{ $alloc->tank->name ?? '—' }}</strong>
                                                                <span class="text-muted">({{ $alloc->tank->warehouse->name ?? '—' }})</span>
                                                            </div>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="font-monospace fw-bold">{{ number_format($alloc->quantity) }} L</span>
                                                                @if (Auth::user()->canEditModule2())
                                                                    <form method="POST" action="{{ route('wetstock.deliveries.unassign', $alloc->id) }}" class="d-inline" onsubmit="return confirm('Remove {{ number_format($alloc->quantity) }}L allocation from {{ $alloc->tank->name }}?');">
                                                                        @csrf
                                                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Remove tank allocation">
                                                                            <i class="bi bi-x-circle"></i>
                                                                        </button>
                                                                    </form>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1" style="color: #a16207 !important;">
                                                    HOLD (Pending)
                                                </span>
                                            </td>
                                            <td class="wetstock-activity-cell">
                                                <div class="text-dark">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</div>
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <div class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</div>
                                                @endif
                                            </td>
                                            <td class="pe-3 text-end">
                                                @if (Auth::user()->canMarkFulfilled())
                                                    <form method="POST" action="{{ route('wetstock.deliveries.fulfill', $delivery->id) }}" class="d-inline" onsubmit="return confirm('Mark DR #{{ $delivery->dr_number }} as FULFILLED? This will officially dispatch fuel from the assigned tanks.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                                                            <i class="bi bi-check-lg me-1"></i> Mark as Fulfilled
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="text-muted small"><i class="bi bi-lock me-1"></i>Awaiting Ops Mgr</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            {{ $assignedDeliveries->appends(['tab' => 'assigned', 'search' => $search ?? null])->links() }}
                        </div>
                    @endif
                </div>
            </div>

        {{-- ==================== TAB 3: HISTORY (FULFILLED) ==================== --}}
        @else
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                <span class="badge rounded-pill px-3 py-2" style="background-color: #eef2ff !important; color: #4338ca !important;">
                    <i class="bi bi-calendar3 me-1"></i> Monthly Summary — {{ \Carbon\Carbon::create($historyYear, $historyMonth, 1)->format('F Y') }}
                    @if ($historyShowAll)
                        <span class="ms-1 badge bg-dark text-white">All Records</span>
                    @endif
                </span>
                <form method="GET" action="{{ route('wetstock.deliveries.index') }}" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="tab" value="history">
                    @if (!empty($search))
                        <input type="hidden" name="search" value="{{ $search }}">
                    @endif
                    <select name="history_month" class="form-select form-select-sm" style="width:auto;">
                        @for ($m = 1; $m <= 12; $m++)
                            <option value="{{ $m }}" {{ $m == $historyMonth ? 'selected' : '' }}>{{ \Carbon\Carbon::create(2000, $m, 1)->format('M') }}</option>
                        @endfor
                    </select>
                    <select name="history_year" class="form-select form-select-sm" style="width:auto;">
                        @for ($y = (int) $now->format('Y'); $y >= 2024; $y--)
                            <option value="{{ $y }}" {{ $y == $historyYear ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary-custom">Go</button>
                    @if (!$historyShowAll)
                        <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'history_all' => 1]) }}" class="btn btn-sm btn-outline-secondary">Show All</a>
                    @else
                        <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history']) }}" class="btn btn-sm btn-outline-secondary">Current Month</a>
                    @endif
                </form>
            </div>
            @if ($historyMonths->isNotEmpty())
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="text-muted small">Past months:</span>
                    @foreach ($historyMonths as $ym)
                        @php [$yy,$mm] = explode('-', $ym); @endphp
                        <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'history_year' => $yy, 'history_month' => $mm]) }}" class="badge rounded-pill border text-decoration-none {{ $yy == $historyYear && $mm == $historyMonth ? 'bg-primary text-white' : 'bg-light text-dark' }}">{{ \Carbon\Carbon::create($yy, $mm, 1)->format('M Y') }}</a>
                    @endforeach
                </div>
            @endif
            <div class="card card-custom p-3 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($historyDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-clock-history display-4 text-muted mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">No Fulfilled Deliveries for {{ \Carbon\Carbon::create($historyYear, $historyMonth, 1)->format('F Y') }}</h5>
                            <p class="text-muted">Try another month or <a href="{{ route('wetstock.deliveries.index', ['tab' => 'history', 'history_all' => 1]) }}">show all records</a>.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 wetstock-deliveries-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR# <i class="bi bi-arrow-down text-muted" title="Sorted highest → lowest"></i></th>
                                        <th class="py-3">ATL#</th>
                                        <th class="py-3">Client</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Volume</th>
                                        <th class="py-3">Tanks Dispatched From</th>
                                        <th class="py-3">Status</th>
                                        <th class="py-3">Activity</th>
                                        <th class="py-3">Fulfillment Time</th>
                                        @if (Auth::user()->canMarkFulfilled())
                                            <th class="pe-3 py-3 text-end">Action</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($historyDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-semibold text-dark">{{ $delivery->dr_number }}</td>
                                            <td class="small">
                                                @if (!empty($delivery->atl_number))
                                                    {{ $delivery->atl_number }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="client-cell" title="{{ $delivery->order->account ?? '-' }}">
                                                <div class="client-name">{{ $delivery->order->account ?? '-' }}</div>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill wetstock-type-badge">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill wetstock-type-badge">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill wetstock-type-badge">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="fw-bold text-dark font-monospace">
                                                {{ number_format($delivery->qty_out) }} L
                                                @if ($delivery->items->count() > 1)
                                                    <div class="text-muted compartment-line">{{ $delivery->items_summary }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    @foreach ($delivery->allocations as $alloc)
                                                        <span class="badge bg-light text-dark border px-2 py-1 small">
                                                            @if ($alloc->item){{ $alloc->item->product_type ?: '-' }} &middot; @endif{{ $alloc->tank->name ?? '—' }} ({{ number_format($alloc->quantity) }}L)
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">
                                                    FULFILLED
                                                </span>
                                            </td>
                                            <td class="wetstock-activity-cell">
                                                <div class="text-dark">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</div>
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <div class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</div>
                                                @endif
                                            </td>
                                            <td class="text-muted small">
                                                {{ $delivery->fulfilled_at ? $delivery->fulfilled_at->timezone('Asia/Manila')->format('M d, Y h:i A') : ($delivery->updated_at ? $delivery->updated_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—') }}
                                            </td>
                                            @if (Auth::user()->canMarkFulfilled())
                                                <td class="pe-3 text-end">
                                                    <form method="POST" action="{{ route('wetstock.deliveries.revert-fulfillment', $delivery->id) }}" class="d-inline" onsubmit="return confirm('Revert DR #{{ $delivery->dr_number }} back to PENDING? This will place the fuel volume back on hold for adjustment.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-warning rounded-pill px-2 py-1 small" title="Revert to Pending">
                                                            <i class="bi bi-arrow-counterclockwise me-1"></i> Revert to Pending
                                                        </button>
                                                    </form>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            {{ $historyDeliveries->appends(['tab' => 'history', 'search' => $search ?? null, 'history_month' => $historyMonth, 'history_year' => $historyYear, 'history_all' => $historyShowAll ? 1 : null])->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        function fmt(n) { return Number(n).toLocaleString('en-US'); }

        // Picking a tank/truck caps the liters at what that tank can actually cover.
        document.querySelectorAll('.alloc-form').forEach(function (form) {
            var tank = form.querySelector('.alloc-tank');
            var qty = form.querySelector('.alloc-qty');
            var hint = form.querySelector('.alloc-hint');
            if (!tank || !qty) { return; }

            tank.addEventListener('change', function () {
                var opt = tank.options[tank.selectedIndex];
                var avail = parseInt(opt ? opt.getAttribute('data-available') : '', 10);
                var remaining = parseInt(qty.getAttribute('data-remaining'), 10);

                if (isNaN(avail)) {
                    qty.max = remaining;
                    qty.value = remaining;
                    hint.textContent = '';
                    return;
                }

                var max = Math.min(avail, remaining);
                qty.max = max;
                qty.value = max;
                hint.textContent = avail < remaining
                    ? 'This tank/truck covers only ' + fmt(avail) + ' L. Allocate the rest to another one.'
                    : 'Max ' + fmt(max) + ' L.';
            });
        });

        // Re-open the pop-up after a partial allocation so the next product can be assigned.
        @if (request('open'))
            var el = document.getElementById('allocModal{{ (int) request('open') }}');
            if (el && window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(el).show();
            }
        @endif
    });
</script>
@endsection