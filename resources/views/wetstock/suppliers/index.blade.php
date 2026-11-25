@extends('layouts.app')

@section('title', 'Manage Suppliers')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
    <div>
        <h2 class="fw-bold text-dark mb-1">Manage Suppliers</h2>
        <p class="text-muted small mb-0">Companies and locations that Purchase Orders draw fuel volume from.</p>
    </div>
    <a href="{{ route('stock-orders.suppliers.create') }}" class="btn btn-primary-custom shadow-sm d-flex align-items-center">
        <i class="bi bi-building-add me-2"></i> Create Supplier
    </a>
</div>

<div class="card card-custom border-0 overflow-hidden">
    <div class="card-body p-0">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Company</th>
                        <th>Location</th>
                        <th>Attention</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($suppliers->isNotEmpty())
                        @foreach ($suppliers as $supplier)
                        <tr>
                            <td class="ps-4 fw-semibold text-dark">
                                {{ $supplier->company_name }}
                                @if ($supplier->address)
                                    <div class="small text-muted">{{ $supplier->address }}</div>
                                @endif
                            </td>
                            <td>{{ $supplier->location !== '' ? $supplier->location : '—' }}</td>
                            <td>{{ $supplier->attention ?: '—' }}</td>
                            <td class="text-center">
                                @if ($supplier->is_active)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">Active</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <a href="{{ route('stock-orders.suppliers.edit', $supplier->id) }}" class="btn btn-sm btn-outline-secondary rounded-3 px-3 py-1" title="Edit Supplier">
                                        <i class="bi bi-pencil me-1"></i> Edit
                                    </a>
                                    <form method="POST" action="{{ route('stock-orders.suppliers.toggle-active', $supplier->id) }}" class="d-inline" onsubmit="return confirm('{{ $supplier->is_active ? 'Deactivate' : 'Reactivate' }} {{ $supplier->company_name }}?');">
                                        @csrf
                                        @if ($supplier->is_active)
                                            <button type="submit" class="btn btn-sm btn-outline-warning rounded-3 px-3 py-1" title="Deactivate Supplier">
                                                <i class="bi bi-pause-circle me-1"></i> Deactivate
                                            </button>
                                        @else
                                            <button type="submit" class="btn btn-sm btn-outline-success rounded-3 px-3 py-1" title="Reactivate Supplier">
                                                <i class="bi bi-play-circle me-1"></i> Reactivate
                                            </button>
                                        @endif
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-building fs-1 d-block mb-3 text-secondary"></i>
                                No suppliers found.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <!-- Phones: the shared .rec-card, the same pattern as every other
             list in Wet Stock. -->
        <div class="rec-cards">
            @forelse ($suppliers as $supplier)
                <div class="rec-card">
                    <div class="rec-card-head">
                        <div class="rec-card-title">{{ $supplier->company_name }}</div>
                        @if ($supplier->is_active)
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">Active</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">Inactive</span>
                        @endif
                    </div>

                    <div class="rec-meta">
                        <div class="rec-span"><span class="rec-k">Location:</span> <span class="rec-v">{{ $supplier->location !== '' ? $supplier->location : '—' }}</span></div>
                        <div class="rec-span"><span class="rec-k">Attention:</span> <span class="rec-v">{{ $supplier->attention ?: '—' }}</span></div>
                        @if ($supplier->address)
                            <div class="rec-span"><span class="rec-k">Address:</span> <span class="rec-v fw-normal">{{ $supplier->address }}</span></div>
                        @endif
                    </div>

                    <div class="rec-actions">
                        <a href="{{ route('stock-orders.suppliers.edit', $supplier->id) }}" class="btn btn-sm btn-outline-secondary rounded-3">
                            <i class="bi bi-pencil me-1"></i> Edit
                        </a>
                        <form method="POST" action="{{ route('stock-orders.suppliers.toggle-active', $supplier->id) }}" onsubmit="return confirm('{{ $supplier->is_active ? 'Deactivate' : 'Reactivate' }} {{ $supplier->company_name }}?');">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $supplier->is_active ? 'btn-outline-warning' : 'btn-outline-success' }} rounded-3">
                                <i class="bi {{ $supplier->is_active ? 'bi-pause-circle' : 'bi-play-circle' }} me-1"></i>
                                {{ $supplier->is_active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="rec-empty">
                    <i class="bi bi-building fs-1 d-block mb-3 text-secondary"></i>
                    No suppliers found.
                </div>
            @endforelse
        </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-center mt-4">
    {{ $suppliers->links() }}
</div>
@endsection
