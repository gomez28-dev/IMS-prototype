<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistics\Concerns\TankerData;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\LogisticsTrip;
use App\Models\LogisticsTripStop;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Services\Logistics\TripScheduling;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The trip board: a month/week/day grid of tankers, the side panel with one
 * tanker's day, and every trip/stop/status/date-change write.
 *
 * Reads use the Module 4 tables; writes go through TripScheduling.
 */
class TripScheduleController extends Controller
{
    use TankerData;

    public function __construct(private readonly TripScheduling $scheduling)
    {
    }

    public function index(Request $request): View
    {
        $view = in_array($request->query('view'), ['month', 'week', 'day'], true)
            ? $request->query('view')
            : 'week';

        try {
            $date = Carbon::parse($request->query('date', 'today'))->startOfDay();
        } catch (\Throwable) {
            $date = Carbon::today();
        }

        if ($view === 'month') {
            $from = $date->copy()->startOfMonth()->startOfWeek();
            $to = $date->copy()->endOfMonth()->endOfWeek()->startOfDay();
            $prev = $date->copy()->startOfMonth()->subMonthNoOverflow();
            $next = $date->copy()->startOfMonth()->addMonthNoOverflow();
            $label = $date->format('F Y');
        } elseif ($view === 'week') {
            $from = $date->copy()->startOfWeek();
            $to = $from->copy()->addDays(6);
            $prev = $from->copy()->subWeek();
            $next = $from->copy()->addWeek();
            $label = $from->format('M j') . ' - ' . $to->format('M j, Y');
        } else {
            $from = $date->copy();
            $to = $date->copy();
            $prev = $date->copy()->subDay();
            $next = $date->copy()->addDay();
            $label = $date->format('l, M j, Y');
        }

        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $days[] = $day->copy();
        }

        $tankers = $this->tankers();
        $tankIds = $tankers->pluck('id');

        $trips = LogisticsTrip::query()
            ->whereIn('storage_tank_id', $tankIds)
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->with(['stops.lines', 'driver', 'helper'])
            ->orderBy('id')
            ->get()
            ->map(fn (LogisticsTrip $trip) => $this->tripRow($trip))
            ->values();

        $wsDeliveries = $this->deliveryRows($tankIds)
            ->whereBetween('delivery_date', [$from->startOfDay(), $to->copy()->endOfDay()])
            ->get();
        foreach ($wsDeliveries as $row) {
            $row->sched_date = Carbon::parse($row->delivery_date)->toDateString();
        }

        $unscheduled = $this->deliveryRows($tankIds)
            ->whereNull('d.delivery_date')
            ->get()
            ->groupBy('delivery_id')
            ->map(function (Collection $rows) use ($tankers) {
                $first = $rows->first();

                return (object) [
                    'delivery_id' => $first->delivery_id,
                    'dr_number'   => $first->dr_number,
                    'so_number'   => $first->so_number,
                    'account'     => $first->account,
                    'location'    => $first->location,
                    'tanker'      => $tankers->firstWhere('id', $first->storage_tank_id),
                    'liters'      => $rows->sum('quantity'),
                    'products'    => $rows->map(fn ($r) => $r->comp_product ?: $r->product_type)
                        ->filter()->unique()->map(fn ($p) => Order::PRODUCT_TYPES[$p] ?? $p)->implode(' / '),
                ];
            })
            ->values();

        return view('logistics.schedule', [
            'view'         => $view,
            'date'         => $date,
            'days'         => $days,
            'tankers'      => $tankers,
            'trips'        => $trips,
            'wsDeliveries' => $wsDeliveries,
            'unscheduled'  => $unscheduled,
            'prev'         => $prev,
            'next'         => $next,
            'label'        => $label,
            'tripTypes'    => LogisticsTrip::TYPES,
            'products'     => $this->productOptions(),
            'drivers'      => $this->activePersonnel()['drivers'],
            'helpers'      => $this->activePersonnel()['helpers'],
            'canEdit'      => auth()->user()->canEditLogistics(),
        ]);
    }

    /** JSON for the side panel: one tanker, one day. */
    public function detail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tank' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);

        $tanker = $this->tankers()->firstWhere('id', (int) $validated['tank']);
        abort_unless($tanker, 404);

        $date = Carbon::parse($validated['date'])->startOfDay();

        $trips = LogisticsTrip::query()
            ->where('storage_tank_id', $tanker->id)
            ->whereDate('scheduled_date', $date->toDateString())
            ->with(['stops.lines', 'driver', 'helper'])
            ->orderBy('id')
            ->get();

        $deliveryRows = $this->deliveryRows(collect([$tanker->id]))
            ->whereDate('d.delivery_date', $date->toDateString())
            ->orderBy('d.id')
            ->get();

        $deliveries = $deliveryRows->groupBy('delivery_id')->map(function (Collection $rows) {
            $first = $rows->first();
            $fulfilled = $first->status === 'FULFILLED';
            $cancelled = $first->status === 'CANCELLED';

            return [
                'id'           => $first->delivery_id,
                'dr_number'    => $first->dr_number,
                'so_number'    => $first->so_number,
                'client'       => $first->account,
                'location'     => $first->location,
                'date'         => $first->delivery_date
                    ? Carbon::parse($first->delivery_date)->toDateString()
                    : null,
                'status_label' => $fulfilled ? 'Fulfilled' : ucfirst(strtolower($first->status)),
                'fulfilled'    => $fulfilled,
                'can_move'     => ! $fulfilled && ! $cancelled,
                'lines'        => $rows->map(fn ($r) => [
                    'compartment_no' => $r->compartment_no,
                    'product_type'   => $r->comp_product ?: $r->product_type,
                    'product'        => Order::PRODUCT_TYPES[$r->comp_product ?: $r->product_type] ?? 'Unspecified',
                    'liters'         => (int) $r->quantity,
                ])->values(),
            ];
        })->values();

        $pending = $this->pendingDateChanges($deliveries->pluck('id'));

        // What the day plans to put into each compartment: the trips' lines
        // plus the Wet Stock allocations for this tanker.
        $planned = $this->scheduling->plannedLitres($this->tankerModel($tanker->id), $date);
        $plannedProducts = $trips->flatMap(fn ($t) => $t->stops->flatMap(fn ($s) => $s->lines))
            ->mapWithKeys(fn ($line) => [$line->compartment_no => $line->product_type])
            ->merge(
                $deliveryRows->mapWithKeys(fn ($r) => [$r->compartment_no => $r->comp_product ?: $r->product_type])
            );

        $compartments = $tanker->compartments->map(fn ($compartment) => [
            'compartment_no'  => $compartment->compartment_no,
            'capacity'        => (int) $compartment->capacity,
            'product_type'    => $compartment->product_type,
            'planned_kl'      => round(($planned[$compartment->compartment_no] ?? 0) / 1000, 3),
            'planned_product' => $plannedProducts[$compartment->compartment_no] ?? null,
        ])->values();

        return response()->json([
            'tanker' => [
                'id'        => $tanker->id,
                'name'      => $tanker->name,
                'code'      => $tanker->code,
                'small'     => $tanker->small,
                'warehouse' => $tanker->warehouse,
                'max'       => $tanker->max_capacity,
            ],
            'date'          => $date->toDateString(),
            'date_label'    => $date->format('l, M j, Y'),
            'day_driver_id' => $trips->firstWhere('driver_id', '!=', null)?->driver_id,
            'day_helper_id' => $trips->firstWhere('helper_id', '!=', null)?->helper_id,
            'day_driver'    => $trips->pluck('driver.name')->filter()->first(),
            'day_helper'    => $trips->pluck('helper.name')->filter()->first(),
            'compartments'  => $compartments,
            'deliveries'    => $deliveries,
            'pending_dates' => $pending,
            'stop_statuses' => $this->stopStatusOptions(),
            'products'      => $this->productOptions(),
            'can_edit'      => auth()->user()->canEditLogistics(),
            'trips'         => $trips->map(fn (LogisticsTrip $trip) => [
                'id'         => $trip->id,
                'trip_no'    => $trip->trip_no,
                'tank'       => $trip->storage_tank_id,
                'date'       => $trip->scheduled_date?->toDateString(),
                'type'       => $trip->trip_type,
                'type_label' => LogisticsTrip::TYPES[$trip->trip_type] ?? ucfirst($trip->trip_type),
                'is_pickup'  => $trip->trip_type === 'pickup',
                'status'     => $trip->status,
                'live'       => $this->liveState($trip),
                'live_label' => $this->liveLabel($trip),
                'driver_id'  => $trip->driver_id,
                'helper_id'  => $trip->helper_id,
                'driver'     => $trip->driver->name ?? null,
                'helper'     => $trip->helper->name ?? null,
                'product'    => $trip->stops->flatMap(fn ($s) => $s->lines)->pluck('product_name')->unique()->implode(' / ') ?: null,
                'supplier'   => $trip->supplier_name,
                'remarks'    => $trip->remarks,
                'stops_done' => $trip->stops->filter(fn ($s) => $s->isDone())->count(),
                'total_kl'   => round($trip->stops->sum(fn ($s) => $s->lines->sum('quantity')) / 1000, 3),
                'stops'      => $trip->stops->map(fn (LogisticsTripStop $stop) => [
                    'id'            => $stop->id,
                    'client_name'   => $stop->client_name,
                    'address'       => $stop->address,
                    'so_no'         => $stop->so_no,
                    'dr_no'         => $stop->dr_no,
                    'wb_no'         => $stop->wb_no,
                    'qty_kl'        => round($stop->total_litres / 1000, 3),
                    'status'        => $stop->status,
                    'status_eta'    => $stop->status_eta,
                    'date_request'  => $stop->delivery_id ? ($pending[$stop->delivery_id] ?? null) : null,
                    'lines'         => $stop->lines->map(fn ($line) => [
                        'compartment_no' => $line->compartment_no,
                        'product_type'   => $line->product_type,
                        'product'        => $line->product_name,
                        'qty_kl'         => $line->quantity_kl,
                        'quantity'       => $line->quantity,
                    ])->values(),
                ])->values(),
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Trips
    // ------------------------------------------------------------------

    public function store(Request $request)
    {
        $this->guardEdit();

        $validated = $this->tripRules($request);

        $trip = $this->scheduling->createTrip($validated, auth()->user());

        return $this->respond($request, "Trip {$trip->trip_no} scheduled.", $trip);
    }

    public function update(Request $request, LogisticsTrip $trip)
    {
        $this->guardEdit();

        $validated = $this->tripRules($request);

        $this->scheduling->updateTrip($trip, $validated, auth()->user());

        return $this->respond($request, "Trip {$trip->trip_no} updated.", $trip);
    }

    public function destroy(LogisticsTrip $trip)
    {
        $this->guardEdit();

        $this->scheduling->cancelTrip($trip, auth()->user());

        return $this->respond(request(), "Trip {$trip->trip_no} cancelled.", $trip);
    }

    // ------------------------------------------------------------------
    // Stops
    // ------------------------------------------------------------------

    public function storeStop(Request $request)
    {
        $this->guardEdit();

        $validated = $request->validate(['trip_id' => ['required', 'integer', 'exists:logistics_trips,id']]
            + $this->stopRules());

        $trip = LogisticsTrip::findOrFail($validated['trip_id']);
        unset($validated['trip_id']);

        $stop = $this->scheduling->addStop($trip, $validated, auth()->user());

        return $this->respond($request, "Stop {$stop->client_name} added.", $stop);
    }

    public function updateStop(Request $request, LogisticsTripStop $stop)
    {
        $this->guardEdit();

        $validated = $request->validate($this->stopRules());

        $this->scheduling->updateStop($stop, $validated, auth()->user());

        return $this->respond($request, "Stop {$stop->client_name} updated.", $stop);
    }

    public function destroyStop(LogisticsTripStop $stop)
    {
        $this->guardEdit();

        $this->scheduling->deleteStop($stop, auth()->user());

        return $this->respond(request(), 'Stop removed.');
    }

    public function statusStop(Request $request, LogisticsTripStop $stop)
    {
        $this->guardEdit();

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(LogisticsTripStop::STATUSES))],
            'eta'    => ['nullable', 'string', 'max:100'],
        ]);

        $this->scheduling->setStopStatus($stop, $validated['status'], auth()->user(), $validated['eta'] ?? null);

        return $this->respond($request, "Stop {$stop->client_name} is now "
            . LogisticsTripStop::STATUSES[$validated['status']] . '.', $stop);
    }

    // ------------------------------------------------------------------
    // Wet Stock delivery date — request, never write
    // ------------------------------------------------------------------

    public function rescheduleDelivery(Request $request, Delivery $delivery)
    {
        $this->guardEdit();

        $validated = $request->validate([
            'delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'reason'        => ['nullable', 'string', 'max:500'],
        ]);

        if ($delivery->status === 'CANCELLED') {
            throw ValidationException::withMessages([
                'delivery_date' => 'A cancelled delivery cannot be rescheduled.',
            ]);
        }

        if ($delivery->status === 'FULFILLED') {
            throw ValidationException::withMessages([
                'delivery_date' => 'This delivery is already fulfilled, so its date cannot be changed.',
            ]);
        }

        $trip = $request->filled('trip_id') ? LogisticsTrip::find($request->input('trip_id')) : null;

        $this->scheduling->requestDateChange(
            $delivery,
            $validated['delivery_date'],
            $validated['reason'] ?: 'Rescheduled from Logistics' . ($trip ? " — trip {$trip->trip_no}" : ''),
            auth()->user()
        );

        return $this->respond($request,
            'Date change requested. The delivery moves once it is approved in the Approvals Queue.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function guardEdit(): void
    {
        abort_unless(auth()->user()->canEditLogistics(), 403,
            'Unauthorized: your role may view Logistics but not change it.');
    }

    private function tripRules(Request $request): array
    {
        return $request->validate([
            'storage_tank_id' => ['required', 'integer', 'exists:storage_tanks,id'],
            'scheduled_date'  => ['required', 'date'],
            'trip_type'       => ['required', Rule::in(array_keys(LogisticsTrip::TYPES))],
            'supplier_name'   => ['nullable', 'string', 'max:150'],
            'driver_id'       => ['nullable', 'integer', 'exists:personnel,id'],
            'helper_id'       => ['nullable', 'integer', 'exists:personnel,id'],
            'remarks'         => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function stopRules(): array
    {
        return [
            'client_name'            => ['required', 'string', 'max:150'],
            'address'                => ['nullable', 'string', 'max:255'],
            'so_no'                  => ['nullable', 'string', 'max:64'],
            'dr_no'                  => ['nullable', 'string', 'max:64'],
            'wb_no'                  => ['nullable', 'string', 'max:64'],
            'delivery_id'            => ['nullable', 'integer', 'exists:deliveries,id'],
            'status_eta'             => ['nullable', 'string', 'max:100'],
            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.compartment_no' => ['required', 'integer', 'min:1'],
            'lines.*.product_type'   => ['nullable', Rule::in(array_keys(Order::PRODUCT_TYPES))],
            'lines.*.quantity'       => ['required', 'integer', 'min:1'],
        ];
    }

    /** JSON for the panel's fetch calls, a redirect for a normal form post. */
    private function respond(Request $request, string $message, ?object $model = null)
    {
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }

    /** Deliveries allocated to these tankers in Wet Stock, one row per allocation. */
    private function deliveryRows(Collection $tankIds)
    {
        return DB::table('delivery_allocations as a')
            ->join('deliveries as d', 'd.id', '=', 'a.delivery_id')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->leftJoin('tank_compartments as c', 'c.id', '=', 'a.tank_compartment_id')
            ->whereIn('a.storage_tank_id', $tankIds)
            ->where('d.status', '!=', 'CANCELLED')
            ->whereNull('d.fulfilled_at')
            ->select(
                'a.storage_tank_id', 'a.quantity',
                'd.id as delivery_id', 'd.dr_number', 'd.delivery_date', 'd.product_type', 'd.status',
                'o.so_number', 'o.account', 'o.location', 'o.date_needed',
                'c.compartment_no', 'c.product_type as comp_product'
            );
    }

    /** @return array<int,string> delivery_id => requested new date */
    private function pendingDateChanges(Collection $deliveryIds): array
    {
        if ($deliveryIds->isEmpty()) {
            return [];
        }

        return ModificationRequest::query()
            ->where('requestable_type', Delivery::class)
            ->whereIn('requestable_id', $deliveryIds)
            ->where('status', 'PENDING')
            ->get()
            ->reduce(function (array $carry, ModificationRequest $request) {
                $new = $request->changes['delivery_date']['new'] ?? null;

                if ($new) {
                    $carry[$request->requestable_id] = $new;
                }

                return $carry;
            }, []);
    }

    private function tripRow(LogisticsTrip $trip): object
    {
        $stops = $trip->stops;

        return (object) [
            'id'              => $trip->id,
            'storage_tank_id' => $trip->storage_tank_id,
            'sched_date'      => $trip->scheduled_date?->toDateString(),
            'trip_no'         => $trip->trip_no,
            'live'            => $this->liveState($trip),
            'driver'          => $trip->driver->name ?? null,
            'helper'          => $trip->helper->name ?? null,
            'product'         => $stops->flatMap(fn ($s) => $s->lines)->pluck('product_name')->unique()->implode(' / ') ?: null,
            'stops_done'      => $stops->filter(fn ($s) => $s->isDone())->count(),
            'stops_total'     => $stops->count(),
        ];
    }

    /** Status word the board colours by. */
    private function liveState(LogisticsTrip $trip): string
    {
        if ($trip->status === 'cancelled') {
            return 'cancelled';
        }

        if ($trip->status === 'completed') {
            return 'completed';
        }

        $open = $trip->stops->first(fn ($s) => ! $s->isDone());

        return $open && $open->status !== 'pending' ? $open->status : 'scheduled';
    }

    private function liveLabel(LogisticsTrip $trip): string
    {
        return LogisticsTripStop::STATUSES[$this->liveState($trip)]
            ?? LogisticsTrip::STATUSES[$this->liveState($trip)]
            ?? 'Planned';
    }
}
