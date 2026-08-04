@extends('layouts.app')

@section('title', 'Archived Report: ' . $snapshot->title)

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-warning text-dark"><i class="bi bi-lock-fill me-1"></i>Archived Snapshot</span>
            <h2 class="fw-bold text-dark mb-0">{{ $snapshot->title }}</h2>
        </div>
        <p class="text-muted small mb-0">Locked historical report frozen on {{ $snapshot->snapshot_date->timezone('Asia/Manila')->format('F d, Y \a\t h:i A') }} by {{ $snapshot->creator->name ?? 'System' }}.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('wetstock.reports.index', ['tab' => 'snapshots']) }}" class="btn btn-secondary-custom d-flex align-items-center">
            <i class="bi bi-arrow-left me-1"></i> Back to Reports
        </a>
        <a href="{{ route('wetstock.reports.export-snapshot', $snapshot->id) }}" class="btn btn-success shadow-sm d-flex align-items-center">
            <i class="bi bi-file-earmark-excel me-2"></i> Export Snapshot Excel
        </a>
    </div>
</div>

<div class="alert alert-warning border-warning shadow-sm rounded-3 mb-4 d-flex align-items-center" role="alert">
    <i class="bi bi-info-circle-fill fs-4 me-3 text-warning-emphasis"></i>
    <div>
        <strong>Locked Historical Archive:</strong> This report represents the exact frozen state of liquid inventory, pending orders, and contamination flags on {{ $snapshot->snapshot_date->timezone('Asia/Manila')->format('M d, Y') }}. It will never retroactively change.
    </div>
</div>

<!-- Detailed Report Block per Warehouse from Snapshot -->
@foreach ($reportData['warehouses'] as $whArray)
    @php
        $whId = $whArray['id'];
        $b = $reportData['blocks'][$whId];
    @endphp
    <div class="card card-custom mb-5 border-0 shadow-sm">
        <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
            <h4 class="fw-bold mb-0 text-white">
                <i class="bi bi-geo-alt-fill me-2 text-warning"></i>{{ strtoupper($whArray['name']) }} REPORT BREAKDOWN (ARCHIVED)
            </h4>
            <span class="small text-light opacity-75">Snapshot Date: {{ $snapshot->snapshot_date->timezone('Asia/Manila')->format('Y-m-d H:i:s') }}</span>
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
                                <td class="small text-muted">Sum of active Depot Tanks at snapshot time</td>
                            </tr>
                            <tr>
                                <td>Tankers Physical Volume</td>
                                <td class="text-end fw-semibold">{{ number_format($b['tankers_total']) }}</td>
                                <td class="small text-muted">Sum of active Tankers at snapshot time</td>
                            </tr>
                            <tr class="{{ $b['contaminated_total'] > 0 ? 'table-danger' : '' }}">
                                <td class="fw-semibold">Contaminated Stock</td>
                                <td class="text-end fw-bold text-danger">{{ number_format($b['contaminated_total']) }}</td>
                                <td class="small text-muted">Flagged contaminated volume at snapshot time</td>
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
        </div>
    </div>
@endforeach

@endsection
