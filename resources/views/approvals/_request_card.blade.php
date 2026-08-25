{{-- Shared modification-request review card. Expects: $req, $confirmMessage, $rejectPlaceholder --}}
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

                <form method="POST" action="{{ route('approvals.approve', $req->id) }}" class="d-inline" onsubmit="return confirm('{{ $confirmMessage }}');">
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
                    <p class="text-muted small">Please provide a note for rejecting this modification to <strong>{{ $req->target_identifier }}</strong>:</p>
                    <div class="mb-3">
                        <textarea name="review_notes" class="form-control" rows="3" placeholder="{{ $rejectPlaceholder }}" required></textarea>
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
