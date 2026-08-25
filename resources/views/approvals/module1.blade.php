@extends('layouts.app')

@section('title', 'Approvals — Sales Orders & DRs')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-cart-check text-primary me-2"></i>Approvals — Module 1: Sales Orders & DRs
                </h3>
                <p class="text-muted small mb-0">Review before/after diffs and approve or reject order &amp; delivery modification requests</p>
            </div>
        </div>

        @if ($module1Requests->isEmpty())
            <div class="card card-custom border-0 p-5 text-center shadow-sm mb-4">
                <i class="bi bi-check2-all text-success display-4 mb-3"></i>
                <h5 class="fw-bold text-dark">All Caught Up!</h5>
                <p class="text-muted mb-0">There are no pending modification requests for Sales Orders or Deliveries.</p>
            </div>
        @else
            <div class="row g-4 mb-4">
                @foreach ($module1Requests as $req)
                    @include('approvals._request_card', [
                        'req' => $req,
                        'confirmMessage' => 'Are you sure you want to approve this request? The changes will immediately overwrite the live record and tag it as Revised.',
                        'rejectPlaceholder' => 'e.g. Quantity mismatch with signed sales contract',
                    ])
                @endforeach
            </div>
            <div class="mb-4">
                {{ $module1Requests->links() }}
            </div>
        @endif

        @include('approvals._history_table', ['historyRequests' => $historyRequests])
    </div>
</div>
@endsection
