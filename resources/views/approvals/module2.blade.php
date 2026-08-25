@extends('layouts.app')

@section('title', 'Approvals — Stock Transfers')

@section('content')
<div class="row justify-content-center">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="bi bi-arrow-left-right text-primary me-2"></i>Approvals — Module 2: Stock Transfers
                </h3>
                <p class="text-muted small mb-0">Review before/after diffs and approve or reject stock transfer modification requests</p>
            </div>
        </div>

        @if ($module2Requests->isEmpty())
            <div class="card card-custom border-0 p-5 text-center shadow-sm mb-4">
                <i class="bi bi-check2-all text-success display-4 mb-3"></i>
                <h5 class="fw-bold text-dark">All Caught Up!</h5>
                <p class="text-muted mb-0">There are no pending modification requests for Wet Stock Transfers.</p>
            </div>
        @else
            <div class="row g-4 mb-4">
                @foreach ($module2Requests as $req)
                    @include('approvals._request_card', [
                        'req' => $req,
                        'confirmMessage' => 'Are you sure you want to approve this transfer correction?',
                        'rejectPlaceholder' => 'e.g. Tank volume discrepancy',
                    ])
                @endforeach
            </div>
            <div class="mb-4">
                {{ $module2Requests->links() }}
            </div>
        @endif

        @include('approvals._history_table', ['historyRequests' => $historyRequests])
    </div>
</div>
@endsection
