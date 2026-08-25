{{-- Shared module-scoped review history table. Expects: $historyRequests --}}
<div class="card card-custom border-0 p-4 shadow-sm">
    <h6 class="fw-semibold text-dark small text-uppercase mb-3" style="letter-spacing: 0.05em;">
        <i class="bi bi-clock-history me-1 text-secondary"></i>Review History
    </h6>
    @if ($historyRequests->isEmpty())
        <div class="text-center py-4 text-muted">
            <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
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
