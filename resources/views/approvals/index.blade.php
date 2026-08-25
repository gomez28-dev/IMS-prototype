@extends('layouts.app')

@section('title', 'Approvals Hub')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-check2-circle text-primary me-2"></i>Approvals Hub
                </h3>
                <p class="text-muted small mb-0">Review before/after diffs and approve or reject modification requests</p>
            </div>
        </div>

        {{-- Nav Tabs --}}
        <ul class="nav nav-pills mb-4 gap-2 bg-white p-2 rounded-4 shadow-sm border">
            @if ($user->canApproveModule1Modification())
            <li class="nav-item">
                <a class="nav-link rounded-3 {{ $activeTab === 'module1' ? 'active bg-primary text-white fw-semibold' : 'text-dark' }}" href="{{ route('approvals.index', ['tab' => 'module1']) }}">
                    <i class="bi bi-cart-check me-1"></i> Module 1: Sales Orders & DRs
                    @if ($module1Count > 0)
                        <span class="badge rounded-pill ms-2 {{ $activeTab === 'module1' ? 'bg-white text-primary' : 'bg-danger text-white' }}">{{ $module1Count }}</span>
                    @endif
                </a>
            </li>
            @endif

            @if ($user->canApproveModule2Modification())
            <li class="nav-item">
                <a class="nav-link rounded-3 {{ $activeTab === 'module2' ? 'active bg-primary text-white fw-semibold' : 'text-dark' }}" href="{{ route('approvals.index', ['tab' => 'module2']) }}">
                    <i class="bi bi-arrow-left-right me-1"></i> Module 2: Stock Transfers
                    @if ($module2Count > 0)
                        <span class="badge rounded-pill ms-2 {{ $activeTab === 'module2' ? 'bg-white text-primary' : 'bg-danger text-white' }}">{{ $module2Count }}</span>
                    @endif
                </a>
            </li>
            @endif

            <li class="nav-item ms-auto">
                <a class="nav-link rounded-3 {{ $activeTab === 'history' ? 'active bg-secondary text-white fw-semibold' : 'text-muted' }}" href="{{ route('approvals.index', ['tab' => 'history']) }}">
                    <i class="bi bi-clock-history me-1"></i> Review History
                </a>
            </li>
        </ul>

        {{-- TAB CONTENT: Module 1 --}}
        @if ($activeTab === 'module1')
            @if ($module1Requests->isEmpty())
                <div class="card card-custom border-0 p-5 text-center shadow-sm">
                    <i class="bi bi-check2-all text-success display-4 mb-3"></i>
                    <h5 class="fw-bold text-dark">All Caught Up!</h5>
                    <p class="text-muted mb-0">There are no pending modification requests for Sales Orders or Deliveries.</p>
                </div>
            @else
                <div class="row g-4">
                    @foreach ($module1Requests as $req)
                        <div class="col-12">
                            <div class="card card-custom border-0 shadow-sm overflow-hidden">
                                <div class="card-header bg-light border-0 py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold">
                                            Request #{{ $req->id }}
                                        </span>
                                        <span class="badge bg-dark text-white rounded-pill px-2.5 py-1">
                                            {{ $req->requestable_type_label }}
                                        </span>
                                        <h6 class="mb-0 fw-bold text-dark ms-1">
                                            {{ $req->target_identifier }}
                                        </h6>
                                    </div>
                                    <div class="text-muted small">
                                        <i class="bi bi-person me-1"></i>Requested by <span class="fw-semibold text-dark">{{ $req->requestedBy->name ?? 'User' }}</span>
                                        <span class="badge {{ $req->requestedBy->role_badge_class ?? '' }} rounded-pill ms-1">{{ $req->requestedBy->role_label ?? '' }}</span>
                                        <span class="ms-2 text-secondary">&bull; {{ $req->created_at ? $req->created_at->diffForHumans() : '' }}</span>
                                    </div>
                                </div>
                                <div class="card-body p-4">
                                    @if ($req->reason)
                                        <div class="alert alert-light border mb-3 py-2 px-3 small text-secondary">
                                            <i class="bi bi-chat-left-quote me-1 text-primary"></i> <strong>Reason:</strong> {{ $req->reason }}
                                        </div>
                                    @endif

                                    <h6 class="fw-semibold text-dark small text-uppercase mb-2" style="letter-spacing: 0.05em;">
                                        <i class="bi bi-pencil-square me-1 text-secondary"></i>Requested Changes (Diff)
                                    </h6>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm table-bordered align-middle mb-0">
                                            <thead class="table-light small">
                                                <tr>
                                                    <th style="width: 25%;">Field</th>
                                                    <th style="width: 37.5%;">Original Value</th>
                                                    <th style="width: 37.5%;" class="table-warning">New Value (Requested)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach (($req->changes ?? []) as $field => $diff)
                                                    <tr>
                                                        <td class="fw-semibold text-dark small">{{ ucwords(str_replace('_', ' ', $field)) }}</td>
                                                        <td class="text-muted small font-monospace">{{ is_null($diff['old'] ?? null) ? '-' : (string)($diff['old']) }}</td>
                                                        <td class="table-warning fw-bold text-dark small font-monospace">{{ is_null($diff['new'] ?? null) ? '-' : (string)($diff['new']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                        {{-- Reject Button & Modal Trigger --}}
                                        <button type="button" class="btn btn-outline-danger btn-sm rounded-3 px-3" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $req->id }}">
                                            <i class="bi bi-x-circle me-1"></i> Reject Request
                                        </button>

                                        {{-- Approve Form --}}
                                        <form method="POST" action="{{ route('approvals.approve', $req->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to approve this request? The changes will immediately overwrite the live record and tag it as Revised.');">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm rounded-3 px-4 fw-semibold text-white shadow-sm">
                                                <i class="bi bi-check-circle me-1"></i> Approve & Apply
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Reject Modal --}}
                        <div class="modal fade" id="rejectModal{{ $req->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content border-0 shadow rounded-4">
                                    <form method="POST" action="{{ route('approvals.reject', $req->id) }}">
                                        @csrf
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold text-danger"><i class="bi bi-x-circle me-1"></i> Reject Request #{{ $req->id }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="text-muted small">Please provide a reason or note for rejecting the modification to <strong>{{ $req->target_identifier }}</strong>:</p>
                                            <div class="mb-3">
                                                <textarea name="review_notes" class="form-control" rows="3" placeholder="e.g. Quantity mismatch with signed sales contract" required></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-0">
                                            <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger rounded-3 px-3">Confirm Rejection</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4">
                    {{ $module1Requests->links() }}
                </div>
            @endif
        @endif

        {{-- TAB CONTENT: Module 2 --}}
        @if ($activeTab === 'module2')
            @if ($module2Requests->isEmpty())
                <div class="card card-custom border-0 p-5 text-center shadow-sm">
                    <i class="bi bi-check2-all text-success display-4 mb-3"></i>
                    <h5 class="fw-bold text-dark">All Caught Up!</h5>
                    <p class="text-muted mb-0">There are no pending modification requests for Wet Stock Transfers.</p>
                </div>
            @else
                <div class="row g-4">
                    @foreach ($module2Requests as $req)
                        <div class="col-12">
                            <div class="card card-custom border-0 shadow-sm overflow-hidden">
                                <div class="card-header bg-light border-0 py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold">
                                            Request #{{ $req->id }}
                                        </span>
                                        <span class="badge bg-dark text-white rounded-pill px-2.5 py-1">
                                            {{ $req->requestable_type_label }}
                                        </span>
                                        <h6 class="mb-0 fw-bold text-dark ms-1">
                                            {{ $req->target_identifier }}
                                        </h6>
                                    </div>
                                    <div class="text-muted small">
                                        <i class="bi bi-person me-1"></i>Requested by <span class="fw-semibold text-dark">{{ $req->requestedBy->name ?? 'User' }}</span>
                                        <span class="badge {{ $req->requestedBy->role_badge_class ?? '' }} rounded-pill ms-1">{{ $req->requestedBy->role_label ?? '' }}</span>
                                        <span class="ms-2 text-secondary">&bull; {{ $req->created_at ? $req->created_at->diffForHumans() : '' }}</span>
                                    </div>
                                </div>
                                <div class="card-body p-4">
                                    @if ($req->reason)
                                        <div class="alert alert-light border mb-3 py-2 px-3 small text-secondary">
                                            <i class="bi bi-chat-left-quote me-1 text-primary"></i> <strong>Reason:</strong> {{ $req->reason }}
                                        </div>
                                    @endif

                                    <h6 class="fw-semibold text-dark small text-uppercase mb-2" style="letter-spacing: 0.05em;">
                                        <i class="bi bi-pencil-square me-1 text-secondary"></i>Requested Changes (Diff)
                                    </h6>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm table-bordered align-middle mb-0">
                                            <thead class="table-light small">
                                                <tr>
                                                    <th style="width: 25%;">Field</th>
                                                    <th style="width: 37.5%;">Original Value</th>
                                                    <th style="width: 37.5%;" class="table-warning">New Value (Requested)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach (($req->changes ?? []) as $field => $diff)
                                                    <tr>
                                                        <td class="fw-semibold text-dark small">{{ ucwords(str_replace('_', ' ', $field)) }}</td>
                                                        <td class="text-muted small font-monospace">{{ is_null($diff['old'] ?? null) ? '-' : (string)($diff['old']) }}</td>
                                                        <td class="table-warning fw-bold text-dark small font-monospace">{{ is_null($diff['new'] ?? null) ? '-' : (string)($diff['new']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                        <button type="button" class="btn btn-outline-danger btn-sm rounded-3 px-3" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $req->id }}">
                                            <i class="bi bi-x-circle me-1"></i> Reject Request
                                        </button>

                                        <form method="POST" action="{{ route('approvals.approve', $req->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to approve this transfer correction?');">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm rounded-3 px-4 fw-semibold text-white shadow-sm">
                                                <i class="bi bi-check-circle me-1"></i> Approve & Apply
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Reject Modal --}}
                        <div class="modal fade" id="rejectModal{{ $req->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content border-0 shadow rounded-4">
                                    <form method="POST" action="{{ route('approvals.reject', $req->id) }}">
                                        @csrf
                                        <div class="modal-header">
                                            <h5 class="modal-title fw-bold text-danger"><i class="bi bi-x-circle me-1"></i> Reject Request #{{ $req->id }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="text-muted small">Please provide a note for rejecting this transfer modification to <strong>{{ $req->target_identifier }}</strong>:</p>
                                            <div class="mb-3">
                                                <textarea name="review_notes" class="form-control" rows="3" placeholder="e.g. Tank volume discrepancy" required></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-0">
                                            <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger rounded-3 px-3">Confirm Rejection</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4">
                    {{ $module2Requests->links() }}
                </div>
            @endif
        @endif

        {{-- TAB CONTENT: Review History --}}
        @if ($activeTab === 'history')
            <div class="card card-custom border-0 p-4 shadow-sm">
                @if ($historyRequests->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-journal-x display-4 text-muted mb-3 d-block"></i>
                        No reviewed modification history recorded yet.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-custom align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Req #</th>
                                    <th>Type</th>
                                    <th>Target Record</th>
                                    <th>Requested By</th>
                                    <th>Status</th>
                                    <th>Reviewed By</th>
                                    <th>Reviewed Date</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($historyRequests as $hist)
                                    <tr>
                                        <td class="fw-bold text-primary">#{{ $hist->id }}</td>
                                        <td><span class="badge bg-light text-dark border">{{ $hist->requestable_type_label }}</span></td>
                                        <td class="fw-semibold text-dark">{{ $hist->target_identifier }}</td>
                                        <td>
                                            <span class="text-dark small">{{ $hist->requestedBy->name ?? 'User' }}</span>
                                        </td>
                                        <td>
                                            @if ($hist->isApproved())
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1"><i class="bi bi-check2 me-1"></i>APPROVED</span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1"><i class="bi bi-x me-1"></i>REJECTED</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="text-muted small">{{ $hist->reviewedBy->name ?? '-' }}</span>
                                        </td>
                                        <td class="text-muted small">
                                            {{ $hist->reviewed_at ? $hist->reviewed_at->format('M d, Y H:i') : '-' }}
                                        </td>
                                        <td class="text-muted small" style="min-width: 220px;">
                                            <span title="{{ $hist->review_notes }}">{{ $hist->review_notes ?: '-' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        {{ $historyRequests->links() }}
                    </div>
                @endif
            </div>
        @endif

    </div>
</div>
@endsection
