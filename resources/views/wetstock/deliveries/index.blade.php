@extends('layouts.app')

@section('title', 'Assign Deliveries & Fulfillment')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="{{ route('wetstock.dashboard') }}" class="text-decoration-none">Wet Stock</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Assign Deliveries</li>
                    </ol>
                </nav>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-truck text-primary me-2"></i>Tank Allocations & Delivery Fulfillment
                </h3>
                <p class="text-muted small mb-0">Allocate sales deliveries to tanks (hold stock) and mark as fulfilled (dispatch fuel).</p>
            </div>
            <div>
                <a href="{{ route('wetstock.dashboard') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- 3 Nav Tabs -->
        <ul class="nav nav-tabs border-bottom mb-4" id="assignTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link fw-semibold {{ $activeTab === 'unassigned' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'unassigned']) }}">
                    <i class="bi bi-inbox me-1 text-warning"></i> 1. Unassigned
                    @if ($unassignedCount > 0)
                        <span class="badge bg-warning text-dark rounded-pill ms-1">{{ $unassignedCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link fw-semibold {{ $activeTab === 'assigned' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'assigned']) }}">
                    <i class="bi bi-check2-circle me-1 text-primary"></i> 2. Assigned (Pending Fulfillment)
                    @if ($assignedCount > 0)
                        <span class="badge bg-primary text-white rounded-pill ms-1">{{ $assignedCount }}</span>
                    @endif
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link fw-semibold {{ $activeTab === 'history' ? 'active text-primary' : 'text-secondary' }}" href="{{ route('wetstock.deliveries.index', ['tab' => 'history']) }}">
                    <i class="bi bi-clock-history me-1 text-success"></i> 3. Fulfillment History
                    @if ($historyCount > 0)
                        <span class="badge bg-secondary-subtle text-secondary rounded-pill ms-1">{{ $historyCount }}</span>
                    @endif
                </a>
            </li>
        </ul>

        {{-- ==================== TAB 1: UNASSIGNED ==================== --}}
        @if ($activeTab === 'unassigned')
            <div class="card card-custom p-4 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($unassignedDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle display-4 text-success mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">All Deliveries Allocated!</h5>
                            <p class="text-muted">There are currently no deliveries awaiting tank assignment.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR Number</th>
                                        <th class="py-3">Account / Client</th>
                                        <th class="py-3">SO Number</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Delivery Date</th>
                                        <th class="py-3">Qty Out</th>
                                        <th class="py-3">Allocated / Remaining</th>
                                        <th class="py-3">DR Created By</th>
                                        <th class="py-3">DR Modified By</th>
                                        @if (Auth::user()->canEditModule2())
                                            <th class="py-3 pe-3">Allocate to Storage Tank</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($unassignedDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark">{{ $delivery->dr_number }}</td>
                                            <td>{{ $delivery->order->account ?? '-' }}</td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    SO# {{ $delivery->order->so_number ?? '-' }}
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill px-2.5 py-1">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill px-2.5 py-1">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill px-2.5 py-1">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="text-muted small">
                                                {{ $delivery->delivery_date ? $delivery->delivery_date->format('M d, Y') : '-' }}
                                            </td>
                                            <td class="fw-bold text-dark">{{ number_format($delivery->qty_out) }} L</td>
                                            <td>
                                                @if ($delivery->allocations->isNotEmpty())
                                                    <span class="text-muted small">{{ number_format($delivery->allocated_quantity) }} L allocated</span>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill d-block mt-1">
                                                        {{ number_format($delivery->remaining_to_allocate) }} L remaining
                                                    </span>
                                                @else
                                                    <span class="badge bg-secondary-subtle text-secondary rounded-pill">0 L / {{ number_format($delivery->qty_out) }} L</span>
                                                @endif
                                            </td>
                                            <td class="small">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</td>
                                            <td class="small">
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <span class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            @if (Auth::user()->canEditModule2())
                                                <td class="pe-3">
                                                    <form method="POST" action="{{ route('wetstock.deliveries.allocate', $delivery->id) }}" class="d-flex gap-2 align-items-center">
                                                        @csrf
                                                        <input type="number" name="quantity" id="allocate-qty-{{ $delivery->id }}" class="form-control form-control-sm allocate-qty-input" style="width: 100px;" min="1" max="{{ $delivery->remaining_to_allocate }}" value="{{ $delivery->remaining_to_allocate }}" title="Quantity to allocate from this DR" required>
                                                        @php
                                                            $siteWarehouse = $warehouses->firstWhere('name', $delivery->order->location ?? null);
                                                        @endphp
                                                        <select name="storage_tank_id" id="allocate-tank-{{ $delivery->id }}" class="form-select form-select-sm allocate-tank-select" style="min-width: 220px;" required>
                                                            <option value="">-- Select Tank ({{ $delivery->order->location ?? 'No Site' }} only) --</option>
                                                            @if (!$siteWarehouse)
                                                                <option value="" disabled>No warehouse matches this order's site ({{ $delivery->order->location ?? '-' }})</option>
                                                            @else
                                                                @foreach ($siteWarehouse->activeTanks as $t)
                                                                    <option value="{{ $t->id }}" data-available="{{ $t->effective_available }}" {{ $t->isFullyContaminated() ? 'disabled' : '' }}>
                                                                        {{ $t->name }} ({{ ucfirst($t->category) }}) — {{ number_format($t->effective_available) }}L Avail
                                                                        {{ $t->hasContamination() ? ' [' . number_format($t->contaminated_liters) . 'L Contaminated' . ($t->isFullyContaminated() ? ' - BLOCKED' : '') . ']' : '' }}
                                                                    </option>
                                                                @endforeach
                                                            @endif
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-primary-custom py-1 px-3">Allocate</button>
                                                    </form>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3">
                            {{ $unassignedDeliveries->appends(['tab' => 'unassigned'])->links() }}
                        </div>
                    @endif
                </div>
            </div>

        {{-- ==================== TAB 2: ASSIGNED (PENDING FULFILLMENT) ==================== --}}
        @elseif ($activeTab === 'assigned')
            <div class="card card-custom p-4 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($assignedDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">No Deliveries Pending Fulfillment</h5>
                            <p class="text-muted">Allocate tanks in the Unassigned tab first. Once allocated, deliveries will appear here awaiting fulfillment.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR Number</th>
                                        <th class="py-3">Account / Client</th>
                                        <th class="py-3">SO#</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Volume</th>
                                        <th class="py-3">Assigned Tanks Breakdown</th>
                                        <th class="py-3">Status</th>
                                        <th class="py-3">DR Created By</th>
                                        <th class="py-3">DR Modified By</th>
                                        <th class="pe-3 py-3 text-end">Fulfillment Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($assignedDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark">{{ $delivery->dr_number }}</td>
                                            <td>{{ $delivery->order->account ?? '-' }}</td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    SO# {{ $delivery->order->so_number ?? '-' }}
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill px-2.5 py-1">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill px-2.5 py-1">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill px-2.5 py-1">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="fw-bold text-dark font-monospace">{{ number_format($delivery->qty_out) }} L</td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    @foreach ($delivery->allocations as $alloc)
                                                        <div class="d-flex align-items-center justify-content-between bg-light rounded px-2 py-1 border small">
                                                            <div>
                                                                <i class="bi bi-fuel-pump text-primary me-1"></i>
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
                                            <td class="small">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</td>
                                            <td class="small">
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <span class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
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
                            {{ $assignedDeliveries->appends(['tab' => 'assigned'])->links() }}
                        </div>
                    @endif
                </div>
            </div>

        {{-- ==================== TAB 3: HISTORY (FULFILLED) ==================== --}}
        @else
            <div class="card card-custom p-4 border-0 shadow-sm">
                <div class="card-body p-0">
                    @if ($historyDeliveries->isEmpty())
                        <div class="text-center py-5">
                            <i class="bi bi-clock-history display-4 text-muted mb-3 d-block"></i>
                            <h5 class="fw-bold text-dark">No Fulfilled Deliveries Yet</h5>
                            <p class="text-muted">Completed deliveries will appear here in the history log.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3 py-3">DR Number</th>
                                        <th class="py-3">Account / Client</th>
                                        <th class="py-3">SO#</th>
                                        <th class="py-3 text-center">Type</th>
                                        <th class="py-3">Volume</th>
                                        <th class="py-3">Tanks Dispatched From</th>
                                        <th class="py-3">Status</th>
                                        <th class="py-3">DR Created By</th>
                                        <th class="py-3">DR Modified By</th>
                                        <th class="py-3">Fulfillment Time</th>
                                        @if (Auth::user()->canMarkFulfilled())
                                            <th class="pe-3 py-3 text-end">Action</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($historyDeliveries as $delivery)
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark">{{ $delivery->dr_number }}</td>
                                            <td>{{ $delivery->order->account ?? '-' }}</td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    SO# {{ $delivery->order->so_number ?? '-' }}
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                @if ($delivery->type === 'PICK-UP')
                                                    <span class="badge badge-type-pickup rounded-pill px-2.5 py-1">PICK-UP</span>
                                                @elseif ($delivery->type === 'SMALL TANKER')
                                                    <span class="badge badge-type-small-tanker rounded-pill px-2.5 py-1">SMALL TANKER</span>
                                                @else
                                                    <span class="badge badge-type-big-tanker rounded-pill px-2.5 py-1">BIG TANKER</span>
                                                @endif
                                            </td>
                                            <td class="fw-bold text-dark font-monospace">{{ number_format($delivery->qty_out) }} L</td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    @foreach ($delivery->allocations as $alloc)
                                                        <span class="badge bg-light text-dark border px-2 py-1 small">
                                                            {{ $alloc->tank->name ?? '—' }} ({{ number_format($alloc->quantity) }}L)
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">
                                                    FULFILLED
                                                </span>
                                            </td>
                                            <td class="small">{{ $delivery->createdBy->name ?? 'Legacy Data' }}</td>
                                            <td class="small">
                                                @php $latestApprovedMod = $delivery->modificationRequests->where('status', 'APPROVED')->sortByDesc('created_at')->first(); @endphp
                                                @if ($latestApprovedMod)
                                                    <span class="text-warning"><i class="bi bi-pencil me-1"></i>{{ $latestApprovedMod->requestedBy->name ?? '—' }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-muted small">
                                                {{ $delivery->updated_at ? $delivery->updated_at->timezone('Asia/Manila')->format('M d, Y h:i A') : '—' }}
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
                            {{ $historyDeliveries->appends(['tab' => 'history'])->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
