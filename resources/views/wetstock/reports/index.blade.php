@extends('layouts.app')

@section('title', 'Wet Stock Report')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Wet Stock Report</h2>
        <p class="text-muted small mb-0">Real-time liquid fuel inventory reconciliation and historical snapshots across all branches.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('wetstock.reports.export-live') }}" class="btn btn-success shadow-sm d-flex align-items-center">
            <i class="bi bi-file-earmark-excel me-2"></i> Download Real-Time Excel
        </a>
        @if (!Auth::user()->isViewer() && !Auth::user()->isAccounting())
        <button type="button" class="btn btn-warning shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#saveSnapshotModal">
            <i class="bi bi-lock-fill me-2"></i> Lock & Save Snapshot
        </button>
        @endif
    </div>
</div>

<!-- Nav Tabs -->
<ul class="nav nav-tabs border-bottom mb-4" id="reportTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <a class="nav-link fw-semibold {{ $activeTab === 'live' ? 'active' : '' }}" id="live-tab" data-bs-toggle="tab" href="#liveReport" role="tab">
            <i class="bi bi-broadcast me-1 text-danger"></i> Live Real-Time Report
        </a>
    </li>
    <li class="nav-item" role="presentation">
        <a class="nav-link fw-semibold {{ $activeTab === 'snapshots' ? 'active' : '' }}" id="snapshots-tab" data-bs-toggle="tab" href="#snapshotsList" role="tab">
            <i class="bi bi-archive me-1 text-primary"></i> Saved Snapshots Archive ({{ $snapshots->total() }})
        </a>
    </li>
</ul>

<div class="tab-content" id="reportTabsContent">
    <!-- TAB 1: LIVE REPORT -->
    <div class="tab-pane fade {{ $activeTab === 'live' ? 'show active' : '' }}" id="liveReport" role="tabpanel">
        
        <!-- Summary Cards per Location -->
        <div class="row g-3 mb-4">
            @foreach ($reportData['warehouses'] as $wh)
                @php $b = $reportData['blocks'][$wh->id]; @endphp
                <div class="col-md-6 col-xl-6">
                    <div class="card card-custom p-4 h-100 border-0 shadow-sm">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-dark mb-0">
                                <i class="bi bi-building me-2 text-primary"></i>{{ $wh->name }} Branch Overview
                            </h5>
                            <span class="badge bg-light text-secondary border">Real-Time</span>
                        </div>
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="p-2 rounded bg-light border">
                                    <div class="text-muted extra-small uppercase fw-semibold">Depots</div>
                                    <div class="fs-6 fw-bold text-dark">{{ number_format($b['depot_total']) }} L</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 rounded bg-light border">
                                    <div class="text-muted extra-small uppercase fw-semibold">Tankers</div>
                                    <div class="fs-6 fw-bold text-dark">{{ number_format($b['tankers_total']) }} L</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 rounded {{ $b['contaminated_total'] > 0 ? 'bg-danger-subtle text-danger border-danger' : 'bg-light border' }}">
                                    <div class="extra-small uppercase fw-semibold">Contaminated</div>
                                    <div class="fs-6 fw-bold">{{ number_format($b['contaminated_total']) }} L</div>
                                </div>
                            </div>
                        </div>
                        <hr class="my-3 text-muted opacity-25">
                        <div class="d-flex justify-content-between align-items-center fs-7">
                            <span class="text-muted">Total Available On-Hand For Selling:</span>
                            <span class="fw-bold fs-5 {{ $b['total_available_on_hand_for_selling'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($b['total_available_on_hand_for_selling']) }} L
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Detailed Report Block per Warehouse -->
        @foreach ($reportData['warehouses'] as $wh)
            @php $b = $reportData['blocks'][$wh->id]; @endphp
            <div class="card card-custom mb-5 border-0 shadow-sm">
                <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                    <h4 class="fw-bold mb-0 text-white">
                        <i class="bi bi-geo-alt-fill me-2 text-warning"></i>{{ strtoupper($wh->name) }} REPORT BREAKDOWN
                    </h4>
                    <span class="small text-light opacity-75">Compiled: {{ $reportData['compiled_at'] }}</span>
                </div>
                <div class="card-body p-4">
                    
                    <!-- TOP SUMMARY TABLE -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-secondary uppercase tracking-wider mb-2">1. Top Summary Statement</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered table-custom mb-0">
                                <thead>
                                    <tr>
                                        <th>Summary Metric</th>
                                        <th class="text-end" style="width: 220px;">Volume (Liters)</th>
                                        <th>Notes / Logic</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Depot Physical Volume</td>
                                        <td class="text-end fw-semibold">{{ number_format($b['depot_total']) }}</td>
                                        <td class="small text-muted">Sum of active Depot Tanks</td>
                                    </tr>
                                    <tr>
                                        <td>Tankers Physical Volume</td>
                                        <td class="text-end fw-semibold">{{ number_format($b['tankers_total']) }}</td>
                                        <td class="small text-muted">Sum of active Tankers</td>
                                    </tr>
                                    <tr class="{{ $b['contaminated_total'] > 0 ? 'table-danger' : '' }}">
                                        <td class="fw-semibold">Contaminated Stock</td>
                                        <td class="text-end fw-bold text-danger">{{ number_format($b['contaminated_total']) }}</td>
                                        <td class="small text-muted">Flagged contaminated tanks/tankers (Deducted from sellable volume)</td>
                                    </tr>
                                    <tr>
                                        <td>Unlifted Supplier Stock Pick Up</td>
                                        <td class="text-end fw-semibold">{{ number_format($b['unlifted_supplier_total']) }}</td>
                                        <td class="small text-muted">Purchased vendor POs awaiting pickup</td>
                                    </tr>
                                    <tr>
                                        <td>Pending Supplier Stock Delivery</td>
                                        <td class="text-end fw-semibold">{{ number_format($b['pending_supplier_total']) }}</td>
                                        <td class="small text-muted">Vendor POs in transit to depot</td>
                                    </tr>
                                    <tr class="table-light fw-bold">
                                        <td>TOTAL COMMITMENTS / PHYSICAL IN</td>
                                        <td class="text-end text-primary fs-6">{{ number_format($b['total_commitments_in']) }}</td>
                                        <td class="small">Depot + Tankers + Contaminated + Unlifted + Pending Supplier</td>
                                    </tr>
                                    <tr class="table-warning">
                                        <td class="fw-bold text-dark">HOLD FOR CLEARING (Sales Documentation)</td>
                                        <td class="text-end fw-bold">{{ number_format($b['pending_clearance_orders_total'] ?? 0) }}</td>
                                        <td class="small text-muted">SUM of qty_ordered where clearance = Pending</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4">Client Unlifted Pick Up</td>
                                        <td class="text-end fw-semibold">{{ number_format($b['client_pickup_total']) }}</td>
                                        <td class="small text-muted">Pending sales orders (Type: PICK-UP)</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4">Client Pending Delivery (Big + Small Tanker)</td>
                                        <td class="text-end fw-semibold">{{ number_format($b['client_pending_delivery_total']) }}</td>
                                        <td class="small text-muted">Big Tanker ({{ number_format($b['big_tanker_total']) }}L) + Small Tanker ({{ number_format($b['small_tanker_total']) }}L)</td>
                                    </tr>
                                    <tr class="table-light fw-bold">
                                        <td>TOTAL (Hold for Clearing)</td>
                                        <td class="text-end text-warning-emphasis fs-6">{{ number_format($b['total_hold_for_clearing']) }}</td>
                                        <td class="small">Hold for Clearing + Client Unlifted Pick Up + Client Pending Delivery</td>
                                    </tr>
                                    <tr class="table-secondary fw-bold">
                                        <td>TOTAL AVAILABLE FOR SALE</td>
                                        <td class="text-end fs-6 text-dark">{{ number_format($b['total_available_for_sale']) }}</td>
                                        <td class="small">TOTAL + TOTAL (Hold for Clearing)</td>
                                    </tr>
                                    <tr class="{{ $b['total_available_on_hand_for_selling'] < 0 ? 'table-danger' : 'table-success' }} fw-bold">
                                        <td class="fs-6">TOTAL AVAILABLE ON HAND FOR SELLING</td>
                                        <td class="text-end fs-5 {{ $b['total_available_on_hand_for_selling'] < 0 ? 'text-danger' : 'text-success' }}">
                                            {{ number_format($b['total_available_on_hand_for_selling']) }} L
                                        </td>
                                        <td class="small">(Depot + Tankers) − Client Unlifted Pick Up</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- BREAKDOWN TABLES GRID -->
                    <div class="row g-4">
                        <!-- Depots Breakdown -->
                        <div class="col-md-6">
                            <div class="border rounded p-3 bg-white h-100">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-box-seam me-2 text-primary"></i>Depots Breakdown</h6>
                                <table class="table table-sm table-striped small mb-0">
                                    <thead>
                                        <tr>
                                            <th>Depot Tank</th>
                                            <th class="text-end">Volume</th>
                                            <th>Status / Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($b['depot_tanks'] as $tank)
                                            <tr>
                                                <td class="fw-semibold">{{ $tank->name }}</td>
                                                <td class="text-end">{{ number_format($tank->stock_available) }} L</td>
                                                <td>
                                                    @if ($tank->is_contaminated)
                                                        <span class="badge bg-danger">CONTAMINATED ({{ number_format($tank->contaminated_liters) }}L)</span>
                                                    @else
                                                        <span class="text-muted">{{ $tank->remarks ?: 'Normal' }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted">No depot tanks configured.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Tankers Breakdown -->
                        <div class="col-md-6">
                            <div class="border rounded p-3 bg-white h-100">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-truck me-2 text-info"></i>Tankers Breakdown</h6>
                                <table class="table table-sm table-striped small mb-0">
                                    <thead>
                                        <tr>
                                            <th>Tanker ID / Plate #</th>
                                            <th class="text-end">Volume</th>
                                            <th>Status / Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($b['tanker_tanks'] as $tank)
                                            <tr>
                                                <td class="fw-semibold">{{ $tank->name }}</td>
                                                <td class="text-end">{{ number_format($tank->stock_available) }} L</td>
                                                <td>
                                                    @if ($tank->is_contaminated)
                                                        <span class="badge bg-danger">CONTAMINATED ({{ number_format($tank->contaminated_liters) }}L)</span>
                                                    @else
                                                        <span class="text-muted">{{ $tank->remarks ?: 'Normal' }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted">No tankers configured.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Unlifted Supplier Pick Up -->
                        <div class="col-md-6">
                            <div class="border rounded p-3 bg-white h-100">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-box-arrow-in-down me-2 text-warning"></i>Unlifted Supplier Pick Up</h6>
                                <table class="table table-sm table-striped small mb-0">
                                    <thead>
                                        <tr>
                                            <th>PO#</th>
                                            <th>Supplier</th>
                                            <th class="text-end">Liters</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($b['unlifted_supplier_orders'] as $po)
                                            <tr>
                                                <td class="fw-semibold">{{ $po->po_number }}</td>
                                                <td>{{ $po->supplier_name }}</td>
                                                <td class="text-end">{{ number_format($po->liters) }} L</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted">No unlifted supplier orders.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Pending Supplier Delivery -->
                        <div class="col-md-6">
                            <div class="border rounded p-3 bg-white h-100">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-truck-flatbed me-2 text-success"></i>Pending Supplier Stock Delivery</h6>
                                <table class="table table-sm table-striped small mb-0">
                                    <thead>
                                        <tr>
                                            <th>PO#</th>
                                            <th>Supplier</th>
                                            <th class="text-end">Liters</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($b['pending_supplier_orders'] as $po)
                                            <tr>
                                                <td class="fw-semibold">{{ $po->po_number }}</td>
                                                <td>{{ $po->supplier_name }}</td>
                                                <td class="text-end">{{ number_format($po->liters) }} L</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted">No pending supplier deliveries.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Big Tanker Undelivered -->
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-white h-100">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-truck me-2 text-danger"></i>Big Tanker Undelivered</h6>
                                <table class="table table-sm table-striped small mb-0">
                                    <thead>
                                        <tr>
                                            <th>SO#</th>
                                            <th>Client</th>
                                            <th class="text-end">Liters</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($b['big_tanker_deliveries'] as $del)
                                            <tr>
                                                <td class="fw-semibold">{{ $del->order->so_number ?? 'N/A' }}</td>
                                                <td>{{ $del->order->account ?? 'N/A' }}</td>
                                                <td class="text-end">{{ number_format($del->qty_out) }} L</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted">None.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Small Tanker Undelivered -->
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-white h-100">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-minecart me-2 text-indigo"></i>Small Tanker Undelivered</h6>
                                <table class="table table-sm table-striped small mb-0">
                                    <thead>
                                        <tr>
                                            <th>SO#</th>
                                            <th>Client</th>
                                            <th class="text-end">Liters</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($b['small_tanker_deliveries'] as $del)
                                            <tr>
                                                <td class="fw-semibold">{{ $del->order->so_number ?? 'N/A' }}</td>
                                                <td>{{ $del->order->account ?? 'N/A' }}</td>
                                                <td class="text-end">{{ number_format($del->qty_out) }} L</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted">None.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Client Pick Up -->
                        <div class="col-md-4">
                            <div class="border rounded p-3 bg-white h-100">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-person-badge me-2 text-primary"></i>Client Pick Up</h6>
                                <table class="table table-sm table-striped small mb-0">
                                    <thead>
                                        <tr>
                                            <th>SO#</th>
                                            <th>Client</th>
                                            <th class="text-end">Liters</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($b['client_pickup_deliveries'] as $del)
                                            <tr>
                                                <td class="fw-semibold">{{ $del->order->so_number ?? 'N/A' }}</td>
                                                <td>{{ $del->order->account ?? 'N/A' }}</td>
                                                <td class="text-end">{{ number_format($del->qty_out) }} L</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-center text-muted">None.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        @endforeach

    </div>

    <!-- TAB 2: SAVED SNAPSHOTS ARCHIVE -->
    <div class="tab-pane fade {{ $activeTab === 'snapshots' ? 'show active' : '' }}" id="snapshotsList" role="tabpanel">
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th>Snapshot Title</th>
                                <th>Saved Date</th>
                                <th>Saved By</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($snapshots as $snap)
                                <tr>
                                    <td class="fw-bold text-dark">
                                        <i class="bi bi-file-earmark-lock me-2 text-warning"></i>{{ $snap->title }}
                                    </td>
                                    <td>{{ $snap->snapshot_date->timezone('Asia/Manila')->format('M d, Y h:i A') }}</td>
                                    <td>{{ $snap->creator->name ?? 'System' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('wetstock.reports.show-snapshot', $snap->id) }}" class="btn btn-outline-primary btn-sm me-1">
                                            <i class="bi bi-eye me-1"></i> View Snapshot
                                        </a>
                                        <a href="{{ route('wetstock.reports.export-snapshot', $snap->id) }}" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No archived snapshots saved yet. Click "Lock & Save Snapshot" on the live report tab to create one.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    {{ $snapshots->appends(['tab' => 'snapshots'])->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Save Report Snapshot -->
@if (!Auth::user()->isViewer() && !Auth::user()->isAccounting())
<div class="modal fade" id="saveSnapshotModal" tabindex="-1" aria-labelledby="saveSnapshotModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form action="{{ route('wetstock.reports.snapshot') }}" method="POST">
                @csrf
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold" id="saveSnapshotModalLabel"><i class="bi bi-lock-fill me-2"></i>Lock & Save Month-End Snapshot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">Saving a snapshot freezes current real-time stock levels, contamination records, and pending orders into a permanent historical archive that will never retroactively change.</p>
                    <div class="mb-3">
                        <label for="title" class="form-label fw-semibold">Snapshot Title</label>
                        <input type="text" name="title" id="title" class="form-control" placeholder="e.g. August 2026 Month-End Report" value="{{ now()->timezone('Asia/Manila')->format('F Y') }} Month-End Report" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-check-circle me-1"></i>Lock & Save Snapshot</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection
