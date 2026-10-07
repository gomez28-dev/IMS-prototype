<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\ModificationRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    /**
     * Per-product balances for an order, keyed by product code ('U', 'D', 'P',
     * or '-' for an order line whose product was never specified).
     * Quantities come from each DR's compartment lines (delivery_items).
     */
    private function productBalances(Order $order, ?int $excludeDeliveryId = null): array
    {
        $order->loadMissing('items');

        $balances = [];
        foreach ($order->items as $item) {
            $key = $item->product_type ?: '-';
            if (!isset($balances[$key])) {
                $balances[$key] = [
                    'name' => Order::PRODUCT_TYPES[$key] ?? 'Unspecified',
                    'ordered' => 0,
                    'committed' => 0,
                    'cancelled' => 0,
                    'available' => 0,
                ];
            }
            $balances[$key]['ordered'] += (int) $item->qty;
        }

        $deliveries = $order->deliveries()
            ->when($excludeDeliveryId, fn ($q) => $q->where('id', '!=', $excludeDeliveryId))
            ->with('items')
            ->get(['id', 'product_type', 'qty_out', 'status']);

        foreach ($deliveries as $d) {
            // Use compartment lines; fall back to the DR's own product/qty if it has none.
            $lines = $d->items->isNotEmpty()
                ? $d->items->map(fn ($i) => ['key' => $i->product_type ?: '-', 'qty' => (int) $i->qty_out])
                : collect([['key' => $d->product_type ?: '-', 'qty' => (int) $d->qty_out]]);

            foreach ($lines as $line) {
                $key = $line['key'];
                if (!isset($balances[$key])) {
                    continue;
                }
                if (in_array($d->status, ['PENDING', 'FULFILLED'], true)) {
                    $balances[$key]['committed'] += $line['qty'];
                } elseif ($d->status === 'CANCELLED') {
                    $balances[$key]['cancelled'] += $line['qty'];
                }
            }
        }

        foreach ($balances as $key => $b) {
            $balances[$key]['available'] = max($b['ordered'] - $b['cancelled'] - $b['committed'], 0);
        }

        return $balances;
    }

    /**
     * Per-product figures for the order header card on the Deliveries page,
     * keyed by product code ('U', 'D', 'P', or '-' if unspecified).
     *
     * ordered   = qty of that product on the SO
     * cancelled = qty on CANCELLED DRs (reduces what the SO can take)
     * fulfilled = qty on FULFILLED DRs ("Total Out")
     * pending   = qty on PENDING DRs
     * remaining = ordered - cancelled - fulfilled (same rule as the order's Remaining)
     */
    private function productSummary(Order $order): array
    {
        $order->loadMissing('items');

        $summary = [];
        foreach ($order->items as $item) {
            $key = $item->product_type ?: '-';
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'name' => Order::PRODUCT_TYPES[$key] ?? 'Unspecified',
                    'ordered' => 0,
                    'cancelled' => 0,
                    'fulfilled' => 0,
                    'pending' => 0,
                    'remaining' => 0,
                ];
            }
            $summary[$key]['ordered'] += (int) $item->qty;
        }

        $deliveries = $order->deliveries()
            ->with('items')
            ->get(['id', 'product_type', 'qty_out', 'status']);

        foreach ($deliveries as $d) {
            $lines = $d->items->isNotEmpty()
                ? $d->items->map(fn ($i) => ['key' => $i->product_type ?: '-', 'qty' => (int) $i->qty_out])
                : collect([['key' => $d->product_type ?: '-', 'qty' => (int) $d->qty_out]]);

            foreach ($lines as $line) {
                $key = $line['key'];
                if (!isset($summary[$key])) {
                    continue;
                }
                if ($d->status === 'FULFILLED') {
                    $summary[$key]['fulfilled'] += $line['qty'];
                } elseif ($d->status === 'PENDING') {
                    $summary[$key]['pending'] += $line['qty'];
                } elseif ($d->status === 'CANCELLED') {
                    $summary[$key]['cancelled'] += $line['qty'];
                }
            }
        }

        foreach ($summary as $key => $s) {
            $summary[$key]['remaining'] = max($s['ordered'] - $s['cancelled'] - $s['fulfilled'], 0);
        }

        return $summary;
    }

    /**
     * Sum compartment quantities per product code.
     */
    private function totalsByProduct(array $items): array
    {
        $totals = [];
        foreach ($items as $item) {
            $key = $item['product_type'] ?? '-';
            $key = $key === null || $key === '' ? '-' : $key;
            $totals[$key] = ($totals[$key] ?? 0) + (int) $item['qty_out'];
        }

        return $totals;
    }

    /**
     * Returns an error message if any product's total would exceed its balance.
     */
    private function itemsLimitError(array $balances, string $status, array $totals): ?string
    {
        foreach ($totals as $key => $qty) {
            $b = $balances[$key] ?? null;
            if (!$b) {
                return 'Error: A selected product is not part of this SO.';
            }

            $committed = $b['committed'];
            $cancelled = $b['cancelled'];

            if ($status !== 'CANCELLED') {
                $committed += $qty;
            } else {
                $cancelled += $qty;
            }

            if ($committed > $b['ordered'] - $cancelled) {
                return "Error: Total {$b['name']} on this DR exceeds the remaining {$b['name']} on this SO (Available: " . number_format($b['available']) . ' L).';
            }
        }

        return null;
    }

    /**
     * Normalize validated compartment rows.
     */
    private function normalizeItems(array $rawItems): array
    {
        $items = [];
        $n = 1;
        foreach (array_values($rawItems) as $row) {
            $code = $row['product_type'];
            $items[] = [
                'product_type' => $code === '-' ? null : $code,
                'qty_out' => (int) $row['qty_out'],
                'compartment_no' => $n++,
            ];
        }

        return $items;
    }

    /**
     * Header product_type: the single product if all lines match, else null (mixed).
     */
    private function headerProduct(array $items): ?string
    {
        $codes = collect($items)->pluck('product_type')->unique()->values();

        return $codes->count() === 1 ? $codes->first() : null;
    }

    private function itemsSummary(array $items): string
    {
        return collect($items)
            ->map(fn ($i) => ($i['product_type'] ?: '-') . ' ' . number_format($i['qty_out']) . 'L')
            ->implode(' + ');
    }

    private function itemRules(array $productKeys): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_type' => ['required', 'string', Rule::in($productKeys)],
            'items.*.qty_out' => ['required', 'integer', 'min:1'],
        ];
    }

    private function itemMessages(): array
    {
        return [
            'items.required' => 'Add at least one product line.',
            'items.min' => 'Add at least one product line.',
            'items.*.product_type.required' => 'Select a product for every line.',
            'items.*.qty_out.required' => 'Enter a quantity for every line.',
            'items.*.qty_out.min' => 'Each quantity must be at least 1 liter.',
            'items.*.qty_out.integer' => 'Each quantity must be a whole number of liters.',
        ];
    }

    /**
     * Display a listing of deliveries for a specific order.
     */
    public function index(Order $order, Request $request): View
    {
        $order->load(['linkedPurchaseOrder.deliveries.preparer', 'linkedPurchaseOrder.deliveries.approver']);
        $deliveries = $order->deliveries()->with('items')->orderBy('delivery_date', 'asc')->get();

        $filterKeys = ['from', 'to', 'month', 'year', 'type', 'account'];
        $filters = [];
        foreach ($filterKeys as $key) {
            if ($request->has($key)) {
                $filters[$key] = $request->input($key);
            }
        }
        if (!empty($filters)) {
            session(['report_filters' => $filters]);
        }

        return view('deliveries.index', [
            'order' => $order,
            'deliveries' => $deliveries,
            'productSummary' => $this->productSummary($order),
        ]);
    }

    /**
     * Show the form for creating a new delivery.
     */
    public function create(Order $order): View|RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        if ($order->clearing_status !== 'Approved') {
            return back()->with('warning', 'This order is awaiting Accounting clearance before delivery can be created.');
        }

        return view('deliveries.form', [
            'title' => 'New Delivery',
            'order' => $order,
            'delivery' => null,
            'products' => $this->productBalances($order),
        ]);
    }

    /**
     * Store a newly created delivery in storage.
     */
    public function store(Request $request, Order $order): RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        if ($order->clearing_status !== 'Approved') {
            return back()->with('warning', 'This order is awaiting Accounting clearance before delivery can be created.');
        }

        $allowedStatuses = Auth::user()->canMarkFulfilled()
            ? 'PENDING,FULFILLED,CANCELLED'
            : 'PENDING,CANCELLED';

        $productKeys = array_keys($this->productBalances($order));

        $validated = $request->validate(array_merge([
            'dr_number' => ['required', 'string', 'max:64', 'unique:deliveries,dr_number'],
            'atl_number' => ['nullable', 'string', 'max:64'],
            'delivery_date' => ['required', 'date'],
            'status' => ['required', 'string', "in:{$allowedStatuses}"],
            'type' => ['required', 'string', 'in:PICK-UP,BIG TANKER,SMALL TANKER,DELIVERY'],
            'remarks' => ['nullable', 'string'],
        ], $this->itemRules($productKeys)), $this->itemMessages());

        $items = $this->normalizeItems($validated['items']);
        $totalQty = array_sum(array_column($items, 'qty_out'));
        $totals = $this->totalsByProduct($items);

        $header = collect($validated)->except('items')->all();
        $header['product_type'] = $this->headerProduct($items);
        $header['qty_out'] = $totalQty;

        return DB::transaction(function () use ($order, $header, $items, $totals, $totalQty) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // 1) Per-product balance
            $error = $this->itemsLimitError($this->productBalances($locked), $header['status'], $totals);
            if ($error) {
                return back()->withInput()->with('danger', $error);
            }

            // 2) Overall SO balance
            $committed = $locked->committed_qty_out;
            $cancelled = $locked->total_cancelled_qty;

            if ($header['status'] !== 'CANCELLED') {
                $committed += $totalQty;
            } else {
                $cancelled += $totalQty;
            }

            if ($committed > $locked->qty_ordered - $cancelled) {
                $available = max($locked->effective_qty_ordered - $locked->committed_qty_out, 0);
                return back()->withInput()->with('danger', 'Error: Delivery quantity would exceed the SO remaining quantity (Available: ' . $available . 'L).');
            }

            $delivery = $locked->deliveries()->create(array_merge($header, ['created_by' => Auth::id()]));
            $delivery->items()->createMany($items);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'CREATED',
                'description' => "Created delivery {$delivery->dr_number} ({$this->itemsSummary($items)}) for order #{$locked->id} - {$locked->account}",
            ]);

            return redirect()->route('order.deliveries', $locked->id)
                ->with('success', 'Delivery added successfully.');
        });
    }

    /**
     * Show the form for editing the specified delivery.
     */
    public function edit(Delivery $delivery): View
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        $order = $delivery->order;
        $delivery->load('items');

        return view('deliveries.form', [
            'title' => 'Edit Delivery',
            'order' => $order,
            'delivery' => $delivery,
            'products' => $this->productBalances($order, $delivery->id),
        ]);
    }

    /**
     * Update the specified delivery via Modification Request workflow.
     */
    public function update(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }
        $order = $delivery->order;
        $delivery->load('items');

        $allowedStatuses = (Auth::user()->canMarkFulfilled() || $delivery->status === 'FULFILLED')
            ? 'PENDING,FULFILLED,CANCELLED'
            : 'PENDING,CANCELLED';

        $productKeys = array_keys($this->productBalances($order, $delivery->id));

        $validated = $request->validate(array_merge([
            'dr_number' => ['required', 'string', 'max:64', 'unique:deliveries,dr_number,' . $delivery->id],
            'atl_number' => ['nullable', 'string', 'max:64'],
            'delivery_date' => ['required', 'date'],
            'status' => ['required', 'string', "in:{$allowedStatuses}"],
            'type' => ['required', 'string', 'in:PICK-UP,BIG TANKER,SMALL TANKER,DELIVERY'],
            'remarks' => ['nullable', 'string'],
            'modification_reason' => ['nullable', 'string', 'max:1000'],
        ], $this->itemRules($productKeys)), $this->itemMessages());

        $items = $this->normalizeItems($validated['items']);
        $totalQty = array_sum(array_column($items, 'qty_out'));
        $totals = $this->totalsByProduct($items);

        $validated['product_type'] = $this->headerProduct($items);
        $validated['qty_out'] = $totalQty;

        // Existing compartment lines in the same shape as the new ones
        $oldItems = $delivery->items->values()->map(fn ($i, $idx) => [
            'product_type' => $i->product_type,
            'qty_out' => (int) $i->qty_out,
            'compartment_no' => $idx + 1,
        ])->all();
        if (empty($oldItems) && $delivery->qty_out > 0) {
            $oldItems = [[
                'product_type' => $delivery->product_type,
                'qty_out' => (int) $delivery->qty_out,
                'compartment_no' => 1,
            ]];
        }

        $changes = [];
        $comparableFields = ['dr_number', 'atl_number', 'delivery_date', 'product_type', 'qty_out', 'status', 'type', 'remarks'];

        foreach ($comparableFields as $field) {
            $oldVal = $delivery->{$field};
            if ($field === 'delivery_date' && $oldVal) {
                $oldVal = $oldVal->format('Y-m-d');
            }
            $newVal = $validated[$field] ?? null;

            if ((string) $oldVal !== (string) $newVal) {
                $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        if (json_encode($oldItems) !== json_encode($items)) {
            $changes['items'] = ['old' => $oldItems, 'new' => $items];
        }

        if (empty($changes)) {
            return redirect()->route('order.deliveries', $order->id)
                ->with('info', "No changes detected on DR# {$delivery->dr_number}.");
        }

        if (isset($changes['items']) || isset($changes['qty_out']) || isset($changes['status']) || isset($changes['product_type'])) {
            // 1) Per-product balance (this delivery excluded from its own totals)
            $error = $this->itemsLimitError($this->productBalances($order, $delivery->id), $validated['status'], $totals);
            if ($error) {
                return back()->withInput()->with('danger', $error);
            }

            // 2) Overall SO balance (skipped for Fuel Trade)
            if (!$order->isFuelTrade()) {
                $committed = $order->committed_qty_out;
                $cancelled = $order->total_cancelled_qty;

                if (in_array($delivery->status, ['PENDING', 'FULFILLED'], true)) {
                    $committed -= (int) $delivery->qty_out;
                } elseif ($delivery->status === 'CANCELLED') {
                    $cancelled -= (int) $delivery->qty_out;
                }

                if ($validated['status'] !== 'CANCELLED') {
                    $committed += $totalQty;
                } else {
                    $cancelled += $totalQty;
                }

                if ($committed > $order->qty_ordered - $cancelled) {
                    return back()->withInput()->with('danger', 'Error: Delivery quantity would exceed the SO remaining quantity.');
                }
            }
        }

        $modRequest = ModificationRequest::create([
            'requestable_type' => Delivery::class,
            'requestable_id' => $delivery->id,
            'requested_by' => Auth::id(),
            'changes' => $changes,
            'reason' => $validated['modification_reason'] ?? 'Delivery modification submitted',
            'status' => 'PENDING',
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'REQUESTED',
            'description' => "Submitted Modification Request #{$modRequest->id} for DR# {$delivery->dr_number} (" . count($changes) . " field(s) changed)",
        ]);

        return redirect()->route('order.deliveries', $order->id)
            ->with('success', "Modification request for DR# {$delivery->dr_number} submitted to the Approvals Queue for HOD / Administrator review.");
    }

    /**
     * Remove the specified delivery from storage.
     */
    public function destroy(Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $orderId = $delivery->order_id;
        $order = $delivery->order;

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'DELETED',
            'description' => "Deleted delivery {$delivery->dr_number} for order #{$order->id} - {$order->account}",
        ]);

        $delivery->delete();

        return redirect()->route('order.deliveries', $orderId)
            ->with('success', 'Delivery deleted successfully.');
    }
}