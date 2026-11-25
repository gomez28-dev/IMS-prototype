@extends('layouts.app')

@section('title', 'Stock IN Log History')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-fuel-pump text-primary me-2"></i>Stock IN Log History
                </h3>
                <p class="text-muted small mb-0">Audit log of all fuel added to storage tanks — corrections are recorded</p>
            </div>
            @if (Auth::user()->canEditModule2())
                <div>
                    <a href="{{ route('wetstock.stock-in.create') }}" class="btn btn-primary-custom btn-sm">
                        <i class="bi bi-plus-circle me-1"></i> Log Stock IN
                    </a>
                </div>
            @endif
        </div>

        <div class="card card-custom p-4 border-0 shadow-sm">
            <div class="card-body">
                @if ($stockIns->isEmpty())
                    <div class="text-center py-5">
                        <i class="bi bi-journal-x display-4 text-muted mb-3 d-block"></i>
                        <p class="text-muted mb-3">No Stock IN entries logged yet.</p>
                        @if (Auth::user()->canEditModule2())
                            <a href="{{ route('wetstock.stock-in.create') }}" class="btn btn-primary-custom btn-sm">
                                <i class="bi bi-plus-circle me-1"></i> Log First Stock IN
                            </a>
                        @endif
                    </div>
                @else
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-custom align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Warehouse</th>
                                    <th>Storage Tank</th>
                                    <th>Quantity Added</th>
                                    <th>Logged By</th>
                                    <th>Logged Timestamp</th>
                                    @if (Auth::user()->canEditModule2())
                                        <th class="text-end">Action</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stockIns as $stockIn)
                                    @php
                                        $isReversal = $stockIn->reverses_id !== null;
                                        $isReverted = $stockIn->reversal !== null;
                                    @endphp
                                    <tr>
                                        <td class="fw-medium text-dark">{{ $stockIn->date ? $stockIn->date->format('M d, Y') : '-' }}</td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                {{ $stockIn->tank->warehouse->name ?? '-' }}
                                            </span>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-box-seam me-1 text-secondary"></i>{{ $stockIn->tank->name ?? '-' }}
                                            @if ($isReversal)
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill ms-1" title="Reversal of entry #{{ $stockIn->reverses_id }}">REVERSAL</span>
                                            @elseif ($isReverted)
                                                <span class="badge bg-secondary-subtle text-secondary border rounded-pill ms-1">REVERTED</span>
                                            @endif
                                        </td>
                                        @if ($stockIn->quantity < 0)
                                            <td class="fw-bold text-danger">
                                                {{ number_format($stockIn->quantity) }} L
                                            </td>
                                        @else
                                            <td class="fw-bold text-success">
                                                +{{ number_format($stockIn->quantity) }} L
                                            </td>
                                        @endif
                                        <td>
                                            <span class="text-muted small">
                                                <i class="bi bi-person me-1"></i>{{ $stockIn->admin->name ?? 'System' }}
                                            </span>
                                        </td>
                                        <td class="text-muted small">
                                            {{ $stockIn->created_at ? $stockIn->created_at->format('Y-m-d H:i') : '-' }}
                                        </td>
                                        @if (Auth::user()->canEditModule2())
                                            <td class="text-end">
                                                @if (!$isReversal && !$isReverted)
                                                    <a href="{{ route('wetstock.stock-in.edit', $stockIn->id) }}" class="btn btn-sm btn-outline-primary" title="Correct quantity">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <form method="POST" action="{{ route('wetstock.stock-in.revert', $stockIn->id) }}" class="d-inline" onsubmit="return confirm('Revert +{{ number_format($stockIn->quantity) }} L into {{ $stockIn->tank->name ?? 'tank' }}? This logs an offsetting -{{ number_format($stockIn->quantity) }} L entry; the original stays for audit.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Revert entry">
                                                            <i class="bi bi-arrow-counterclockwise"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                                @if (Auth::user()->isAdmin() && !$isReversal && !$isReverted)
                                                    <form method="POST" action="{{ route('wetstock.stock-in.destroy', $stockIn->id) }}" class="d-inline" onsubmit="return confirm('Permanently delete this Stock IN entry (+{{ number_format($stockIn->quantity) }} L into {{ $stockIn->tank->name ?? 'tank' }})? This cannot be undone.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete entry">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Phones: one card per entry. The table above stays for
                         tablet and desktop. --}}
                    <div class="rec-cards">
                        @foreach ($stockIns as $stockIn)
                            @php
                                $isReversal = $stockIn->reverses_id !== null;
                                $isReverted = $stockIn->reversal !== null;
                            @endphp
                            <div class="rec-card">
                                <div class="rec-card-head">
                                    <div class="rec-card-title">
                                        <i class="bi bi-box-seam me-1 text-secondary"></i>{{ $stockIn->tank->name ?? '-' }}
                                        @if ($isReversal)
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill ms-1" title="Reversal of entry #{{ $stockIn->reverses_id }}">REVERSAL</span>
                                        @elseif ($isReverted)
                                            <span class="badge bg-secondary-subtle text-secondary border rounded-pill ms-1">REVERTED</span>
                                        @endif
                                    </div>
                                    <span class="fw-bold text-nowrap {{ $stockIn->quantity < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $stockIn->quantity < 0 ? '' : '+' }}{{ number_format($stockIn->quantity) }} L
                                    </span>
                                </div>

                                <div class="rec-meta">
                                    <div><span class="rec-k">Date:</span> <span class="rec-v">{{ $stockIn->date ? $stockIn->date->format('M d, Y') : '-' }}</span></div>
                                    <div class="rec-r"><span class="rec-k">Warehouse:</span> <span class="rec-v">{{ $stockIn->tank->warehouse->name ?? '-' }}</span></div>
                                    <div><span class="rec-k">Logged by:</span> <span class="rec-v">{{ $stockIn->admin->name ?? 'System' }}</span></div>
                                    <div class="rec-r"><span class="rec-k">Logged:</span> <span class="rec-v">{{ $stockIn->created_at ? $stockIn->created_at->format('Y-m-d H:i') : '-' }}</span></div>
                                </div>

                                @if (Auth::user()->canEditModule2() && !$isReversal && !$isReverted)
                                    <div class="rec-actions">
                                        <a href="{{ route('wetstock.stock-in.edit', $stockIn->id) }}" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-pencil me-1"></i> Correct
                                        </a>
                                        <form method="POST" action="{{ route('wetstock.stock-in.revert', $stockIn->id) }}" onsubmit="return confirm('Revert this entry? This logs an offsetting -{{ number_format($stockIn->quantity) }} L entry; the original stays for audit.');">
                                            @csrf
                                            <button type="submit" class="btn btn-outline-warning btn-sm">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i> Revert
                                            </button>
                                        </form>
                                        @if (Auth::user()->isAdmin())
                                            <form method="POST" action="{{ route('wetstock.stock-in.destroy', $stockIn->id) }}" onsubmit="return confirm('Permanently delete this Stock IN entry? This cannot be undone.');">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-trash me-1"></i> Delete
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3">
                        {{ $stockIns->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
