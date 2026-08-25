<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Models\StockTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ModificationRequestController extends Controller
{
    /**
     * Display centralized Approvals Hub.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        if (!$user->canViewApprovals()) {
            abort(403, 'Unauthorized: You do not have approval permissions.');
        }

        $activeTab = $request->get('tab', 'module1');

        // Module 1 (Sales Orders & Deliveries) Pending
        $module1PendingQuery = ModificationRequest::with(['requestedBy', 'requestable'])
            ->whereIn('requestable_type', [Order::class, Delivery::class])
            ->where('status', 'PENDING')
            ->orderBy('created_at', 'desc');

        $module1Count = (clone $module1PendingQuery)->count();
        $module1Requests = $module1PendingQuery->paginate(15, ['*'], 'm1_page');

        // Module 2 (Wet Stock Transfers) Pending
        $module2PendingQuery = ModificationRequest::with(['requestedBy', 'requestable'])
            ->where('requestable_type', StockTransfer::class)
            ->where('status', 'PENDING')
            ->orderBy('created_at', 'desc');

        $module2Count = (clone $module2PendingQuery)->count();
        $module2Requests = $module2PendingQuery->paginate(15, ['*'], 'm2_page');

        // History
        $historyQuery = ModificationRequest::with(['requestedBy', 'reviewedBy', 'requestable'])
            ->whereIn('status', ['APPROVED', 'REJECTED'])
            ->orderBy('reviewed_at', 'desc');

        $historyRequests = $historyQuery->paginate(20, ['*'], 'history_page');

        return view('approvals.index', [
            'activeTab' => $activeTab,
            'module1Requests' => $module1Requests,
            'module2Requests' => $module2Requests,
            'historyRequests' => $historyRequests,
            'module1Count' => $module1Count,
            'module2Count' => $module2Count,
            'user' => $user,
        ]);
    }

    /**
     * Approve a modification request and apply changes atomically.
     */
    public function approve(Request $request, ModificationRequest $modificationRequest): RedirectResponse
    {
        $user = Auth::user();

        if ($modificationRequest->getModule() === 'Module 1' && !$user->canApproveModule1Modification()) {
            abort(403, 'Unauthorized: Only Portal Admins and HODs can approve Module 1 modifications.');
        }

        if ($modificationRequest->getModule() === 'Module 2' && !$user->canApproveModule2Modification()) {
            abort(403, 'Unauthorized: Only Operations Managers and Portal Admins can approve Module 2 modifications.');
        }

        if (!$modificationRequest->isPending()) {
            return back()->with('warning', 'This request has already been reviewed.');
        }

        $target = $modificationRequest->requestable;
        if (!$target) {
            $modificationRequest->update([
                'status' => 'REJECTED',
                'reviewed_by' => $user->id,
                'reviewed_at' => now('Asia/Manila'),
                'review_notes' => 'Target record no longer exists.',
            ]);
            return back()->with('danger', 'Underlying record no longer exists. Request rejected.');
        }

        DB::transaction(function () use ($modificationRequest, $target, $user, $request) {
            $changes = $modificationRequest->changes ?? [];

            foreach ($changes as $field => $diff) {
                if (isset($diff['new'])) {
                    $target->{$field} = $diff['new'];
                }
            }

            // Tag as revised
            $target->revised_at = now('Asia/Manila');
            $target->save();

            // If cancelling an Order, auto-cancel active deliveries
            if ($target instanceof Order && ($target->status === 'Cancelled')) {
                $target->deliveries()->where('status', '!=', 'CANCELLED')->update([
                    'status' => 'CANCELLED',
                    'revised_at' => now('Asia/Manila'),
                ]);
            }

            $modificationRequest->update([
                'status' => 'APPROVED',
                'reviewed_by' => $user->id,
                'reviewed_at' => now('Asia/Manila'),
                'review_notes' => $request->input('review_notes'),
            ]);

            AuditLog::create([
                'admin_id' => $user->id,
                'action' => 'APPROVED',
                'description' => "Approved Modification Request #{$modificationRequest->id} for {$modificationRequest->target_identifier} (Requested by {$modificationRequest->requestedBy->name})",
            ]);
        });

        return back()->with('success', "Modification Request #{$modificationRequest->id} for {$modificationRequest->target_identifier} approved and applied successfully.");
    }

    /**
     * Reject a modification request.
     */
    public function reject(Request $request, ModificationRequest $modificationRequest): RedirectResponse
    {
        $user = Auth::user();

        if ($modificationRequest->getModule() === 'Module 1' && !$user->canApproveModule1Modification()) {
            abort(403);
        }

        if ($modificationRequest->getModule() === 'Module 2' && !$user->canApproveModule2Modification()) {
            abort(403);
        }

        if (!$modificationRequest->isPending()) {
            return back()->with('warning', 'This request has already been reviewed.');
        }

        $modificationRequest->update([
            'status' => 'REJECTED',
            'reviewed_by' => $user->id,
            'reviewed_at' => now('Asia/Manila'),
            'review_notes' => $request->input('review_notes') ?? 'Rejected by reviewer.',
        ]);

        AuditLog::create([
            'admin_id' => $user->id,
            'action' => 'REJECTED',
            'description' => "Rejected Modification Request #{$modificationRequest->id} for {$modificationRequest->target_identifier}",
        ]);

        return back()->with('success', "Modification Request #{$modificationRequest->id} rejected.");
    }
}
