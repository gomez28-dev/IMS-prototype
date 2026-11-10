<?php

namespace App\Services\Executive;

use App\Models\Admin;
use App\Models\Delivery;
use App\Models\LogisticsTrip;
use App\Models\LogisticsTripStop;
use App\Models\LogisticsUnitStatus;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StorageTank;
use App\Services\Sales\OrderProductStats;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Every figure on the executive overview, in one place.
 *
 * The page this feeds is read-only: this class only ever reads. Each module
 * block returns the same shape so the view can render it generically:
 *
 *   ['key', 'title', 'icon', 'url', 'figures' => [key => [
 *       'label' => string, 'value' => int|float, 'unit' => '', 'hint' => ?string,
 *   ]]]
 *
 * Money is returned raw and formatted by the view; quantities are whole litres.
 */
class ExecutiveSummary
{
    private Carbon $now;

    public function __construct(?CarbonInterface $now = null)
    {
        $this->now = $now
            ? Carbon::instance($now)->setTimezone('Asia/Manila')
            : Carbon::now('Asia/Manila');
    }

    /**
     * @return array<string, mixed>
     */
    public function all(Admin $user): array
    {
        return [
            'period_label' => $this->now->format('F Y'),
            'today_label' => $this->now->format('l, j F Y'),
            'sales' => $this->sales(),
            'wet_stock' => $this->wetStock(),
            'stock_orders' => $this->stockOrders(),
            'logistics' => $this->logistics(),
            'attention' => $this->attention($user),
        ];
    }

    /**
     * Module 1 — Sales Documentation, this month.
     *
     * "Open" figures deliberately use the same carry-over scope as the Module 1
     * dashboard, so the two screens agree on what is still outstanding.
     */
    public function sales(): array
    {
        $start = $this->now->copy()->startOfMonth()->startOfDay();
        $end = $this->now->copy()->endOfMonth()->endOfDay();

        $monthOrders = Order::query()
            ->whereBetween('date', [$start, $end])
            ->where('status', '!=', 'Cancelled')
            ->with(['items', 'deliveries.items'])
            ->get();

        $products = (new OrderProductStats())->forCollection($monthOrders);
        $ordered = array_sum($products['ordered']);
        $delivered = array_sum($products['delivered']);
        $undelivered = array_sum($products['undelivered']);
        $value = (float) $monthOrders->sum('amount');

        $openOrders = Order::query()
            ->activeOrCurrentMonth($this->now)
            ->where('status', '!=', 'Cancelled')
            ->with(['items', 'deliveries.items'])
            ->get();
        $openUndelivered = array_sum((new OrderProductStats())->forCollection($openOrders)['undelivered']);

        $pendingClearance = Order::query()
            ->activeOrCurrentMonth($this->now)
            ->where('status', '!=', 'Cancelled')
            ->where('clearing_status', 'Pending')
            ->count();

        $pct = $ordered > 0 ? (int) round($delivered / $ordered * 100) : 0;

        return [
            'key' => 'sales',
            'title' => 'Sales Documentation',
            'icon' => 'bi-box-seam',
            'url' => route('dashboard'),
            'figures' => [
                'orders' => $this->figure('Orders this month', $monthOrders->count()),
                'ordered' => $this->figure('Litres ordered', $ordered, 'L'),
                'delivered' => $this->figure('Delivered', $delivered, 'L', $pct . '% of ordered'),
                'undelivered' => $this->figure('Undelivered this month', $undelivered, 'L'),
                'value' => $this->figure('Sales value', $value, '₱'),
                'open_undelivered' => $this->figure('Still outstanding', $openUndelivered, 'L', 'this month + carry-over'),
                'pending_clearance' => $this->figure('Awaiting clearance', $pendingClearance, '', 'accounting'),
            ],
        ];
    }

    /**
     * Module 2 — Wet Stock, physical litres in depot tanks right now.
     */
    public function wetStock(): array
    {
        $tanks = StorageTank::query()
            ->where('category', 'depot')
            ->where('is_active', true)
            ->get();

        $onHand = [];
        foreach (OrderProductStats::CODES as $code) {
            $onHand[$code] = $tanks->where('product_type', $code)
                ->sum(fn (StorageTank $tank) => $tank->stock_available);
        }

        $contaminated = (int) $tanks->sum('contaminated_liters');
        $sellable = (int) $tanks->sum(fn (StorageTank $tank) => $tank->sellable_available);
        $total = array_sum($onHand);

        return [
            'key' => 'wet_stock',
            'title' => 'Wet Stock',
            'icon' => 'bi-fuel-pump',
            'url' => route('wetstock.dashboard'),
            'figures' => [
                'diesel' => $this->figure(Order::PRODUCT_TYPES['D'], $onHand['D'], 'L'),
                'unleaded' => $this->figure(Order::PRODUCT_TYPES['U'], $onHand['U'], 'L'),
                'premium' => $this->figure(Order::PRODUCT_TYPES['P'], $onHand['P'], 'L'),
                'total' => $this->figure('Total on hand', $total, 'L', $tanks->count() . ' depot tanks'),
                'sellable' => $this->figure('Sellable', $sellable, 'L'),
                'contaminated' => $this->figure('Contaminated', $contaminated, 'L'),
            ],
        ];
    }

    /**
     * Module 3 — Stock Orders raised this month, plus what is waiting on the VP.
     */
    public function stockOrders(): array
    {
        $start = $this->now->copy()->startOfMonth()->startOfDay();
        $end = $this->now->copy()->endOfMonth()->endOfDay();

        $monthPos = PurchaseOrder::query()
            ->whereBetween('request_date', [$start, $end])
            ->get();

        $awaitingVp = PurchaseOrder::where('request_status', 'RECEIVED')->count();

        $unlifted = PurchaseOrderDelivery::query()
            ->where('lift_status', PurchaseOrderDelivery::LIFT_UNLIFTED)
            ->where('status', '!=', 'Cancelled')
            ->count();

        return [
            'key' => 'stock_orders',
            'title' => 'Stock Orders',
            'icon' => 'bi-cart-check',
            'url' => route('stock-orders.purchase-orders.index'),
            'figures' => [
                'po_count' => $this->figure('Purchase orders', $monthPos->count(), '', 'this month'),
                'po_value' => $this->figure('Purchase value', (float) $monthPos->sum('total_amount'), '₱', 'this month'),
                'awaiting_vp' => $this->figure('Awaiting VP approval', $awaitingVp, '', 'all open'),
                'unlifted' => $this->figure('ATLs not lifted', $unlifted, '', 'stock on the road'),
            ],
        ];
    }

    /**
     * Module 4 — Logistics, today only.
     */
    public function logistics(): array
    {
        $today = $this->now->toDateString();

        $trips = LogisticsTrip::query()
            ->whereDate('scheduled_date', $today)
            ->where('status', '!=', 'cancelled')
            ->with('stops')
            ->get();

        $running = $trips->filter(fn (LogisticsTrip $trip) => $trip->stops
            ->whereIn('status', ['in_transit', 'waiting_to_unload', 'unloading'])
            ->isNotEmpty())
            ->count();

        $dropoffsDue = LogisticsTripStop::query()
            ->whereIn('logistics_trip_id', $trips->pluck('id'))
            ->where('status', '!=', LogisticsTripStop::DONE)
            ->count();

        $unitsInTransit = LogisticsUnitStatus::query()
            ->whereIn('status', ['to_client', 'to_next_drop', 'to_supplier'])
            ->count();

        return [
            'key' => 'logistics',
            'title' => 'Logistics',
            'icon' => 'bi-truck',
            'url' => route('logistics.schedule'),
            'figures' => [
                'trips_today' => $this->figure('Trips today', $trips->count()),
                'running' => $this->figure('Running now', $running),
                'dropoffs_due' => $this->figure('Drop-offs due', $dropoffsDue, '', 'not yet completed'),
                'units_in_transit' => $this->figure('Units in transit', $unitsInTransit),
            ],
        ];
    }

    /**
     * What needs a decision, in the order a CEO would want to see it.
     *
     * An item only appears when the viewing role can actually open it, so the
     * overview never hands the President a link that answers 403.
     *
     * @return array<int, array{key: string, label: string, count: int, hint: string, url: ?string}>
     */
    public function attention(Admin $user): array
    {
        $items = [];

        if ($user->canViewStockApprovals()) {
            $items[] = $this->attentionItem(
                'po_approvals',
                'Purchase orders waiting on the VP',
                PurchaseOrder::where('request_status', 'RECEIVED')->count(),
                'stock-orders.approvals',
            );
        }

        if ($user->canViewLogistics()) {
            $today = $this->now->toDateString();
            $items[] = $this->attentionItem(
                'trips_pending',
                'Drop-offs still open on today\'s trips',
                LogisticsTripStop::query()
                    ->whereHas('trip', fn ($q) => $q->whereDate('scheduled_date', $today)->where('status', '!=', 'cancelled'))
                    ->where('status', '!=', LogisticsTripStop::DONE)
                    ->count(),
                'logistics.schedule',
            );
        }

        if ($user->canEditModule2()) {
            $items[] = $this->attentionItem(
                'unassigned_deliveries',
                'Deliveries with no tank stock assigned',
                Delivery::unassignedToTank()->count(),
                'wetstock.dashboard',
            );
        }

        if ($user->canViewLogistics()) {
            $items[] = $this->attentionItem(
                'tankers_without_compartments',
                'Tankers with no compartments set up',
                StorageTank::where('category', 'tanker')->whereDoesntHave('compartments')->count(),
                'logistics.units',
            );
        }

        if ($user->canApproveModule1Modification()) {
            $items[] = $this->attentionItem(
                'delivery_modifications',
                'Order and delivery changes awaiting approval',
                ModificationRequest::whereIn('requestable_type', [Order::class, Delivery::class])
                    ->where('status', 'PENDING')
                    ->count(),
                'approvals.index',
            );
        }

        return $items;
    }

    /**
     * @return array{label: string, value: int|float, unit: string, hint: ?string}
     */
    private function figure(string $label, int|float $value, string $unit = '', ?string $hint = null): array
    {
        return compact('label', 'value', 'unit', 'hint');
    }

    /**
     * @return array{key: string, label: string, count: int, hint: string, url: string}
     */
    private function attentionItem(string $key, string $label, int $count, string $route): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'hint' => $count === 0 ? 'Nothing waiting' : 'Needs attention',
            'url' => route($route),
        ];
    }
}
