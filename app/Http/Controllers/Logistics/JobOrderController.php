<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistics\Concerns\TankerData;
use App\Models\Delivery;
use App\Models\Order;
use App\Services\Logistics\TripScheduling;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as PaginatorInstance;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Deliveries that still have to go on a trip, the ones already scheduled, and
 * the ones Wet Stock has not loaded onto a tanker yet.
 */
class JobOrderController extends Controller
{
    use TankerData;

    public function __construct(private readonly TripScheduling $scheduling)
    {
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['schedule', 'scheduled', 'waiting'], true)
            ? $request->query('tab')
            : 'schedule';

        $today = Carbon::today();
        $tankers = $this->tankers();
        $tankIds = $tankers->pluck('id');

        $jobs = $this->jobs($tankers, $tankIds);

        $toSchedule = $this->paginate($jobs->filter(fn ($job) => ! $job->trip)->values());
        $scheduled = $this->paginate($jobs->filter(fn ($job) => (bool) $job->trip)->values());
        $waiting = $this->paginate($this->waiting($tankIds));

        return view('logistics.job-orders', [
            'tab'        => $tab,
            'today'      => $today,
            'toSchedule' => $toSchedule,
            'scheduled'  => $scheduled,
            'waiting'    => $waiting,
            'drivers'    => $this->activePersonnel()['drivers'],
            'helpers'    => $this->activePersonnel()['helpers'],
            'canEdit'    => auth()->user()->canEditLogistics(),
        ]);
    }

    public function schedule(Request $request)
    {
        abort_unless(auth()->user()->canEditLogistics(), 403,
            'Unauthorized: your role may view Logistics but not change it.');

        $validated = $request->validate([
            'delivery_id'     => ['required', 'integer', 'exists:deliveries,id'],
            'storage_tank_id' => ['required', 'integer', 'exists:storage_tanks,id'],
            'scheduled_date'  => ['required', 'date'],
            'driver_id'       => ['nullable', 'integer', 'exists:personnel,id'],
            'helper_id'       => ['nullable', 'integer', 'exists:personnel,id'],
        ]);

        $delivery = Delivery::with('order')->findOrFail($validated['delivery_id']);

        if ($delivery->status === 'CANCELLED') {
            return back()->withErrors(['delivery_id' => 'A cancelled delivery cannot be scheduled.']);
        }

        if ($delivery->status === 'FULFILLED') {
            return back()->withErrors(['delivery_id' => 'This delivery is already fulfilled.']);
        }

        $tanker = $this->tankerModel((int) $validated['storage_tank_id']);
        $date = Carbon::parse($validated['scheduled_date'])->startOfDay();

        $trip = $this->scheduling->scheduleFromDelivery(
            $delivery,
            $tanker,
            $date,
            auth()->user(),
            array_filter([
                'driver_id' => $validated['driver_id'] ?? null,
                'helper_id' => $validated['helper_id'] ?? null,
            ], fn ($value) => $value !== null)
        );

        return redirect()
            ->route('logistics.schedule', ['view' => 'day', 'date' => $date->toDateString()])
            ->with('success', "DR# {$delivery->dr_number} scheduled on {$trip->trip_no} for {$date->format('M j, Y')}.");
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Deliveries allocated to a tanker in Wet Stock, one row per allocation,
     * grouped into one job per delivery + tanker with its trip if any.
     */
    private function jobs(Collection $tankers, Collection $tankIds): Collection
    {
        $allocations = DB::table('delivery_allocations as a')
            ->join('deliveries as d', 'd.id', '=', 'a.delivery_id')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->leftJoin('tank_compartments as c', 'c.id', '=', 'a.tank_compartment_id')
            ->whereIn('a.storage_tank_id', $tankIds)
            ->where('d.status', '!=', 'CANCELLED')
            ->whereNull('d.fulfilled_at')
            ->select(
                'a.storage_tank_id', 'a.quantity',
                'd.id as delivery_id', 'd.dr_number', 'd.delivery_date', 'd.product_type',
                'o.so_number', 'o.account', 'o.location', 'o.date_needed',
                'c.compartment_no', 'c.product_type as comp_product'
            )
            ->get();

        $covered = DB::table('logistics_trip_stops as s')
            ->join('logistics_trips as t', 't.id', '=', 's.logistics_trip_id')
            ->whereIn('s.delivery_id', $allocations->pluck('delivery_id')->unique())
            ->where('t.status', '!=', 'cancelled')
            ->select('s.delivery_id', 't.storage_tank_id', 't.trip_no', 't.scheduled_date')
            ->get()
            ->keyBy(fn ($row) => $row->delivery_id . '|' . $row->storage_tank_id);

        return $allocations
            ->groupBy(fn ($row) => $row->delivery_id . '|' . $row->storage_tank_id)
            ->map(function (Collection $rows, string $key) use ($tankers, $covered) {
                $first = $rows->first();
                $required = $first->delivery_date ?: $first->date_needed;
                $trip = $covered->get($key);

                return (object) [
                    'delivery_id' => $first->delivery_id,
                    'tank'        => $tankers->firstWhere('id', $first->storage_tank_id),
                    'dr'          => $first->dr_number,
                    'so'          => $first->so_number,
                    'client'      => $first->account,
                    'location'    => $first->location,
                    'products'    => $rows->map(fn ($r) => $r->comp_product ?: $r->product_type)
                        ->filter()->unique()->map(fn ($p) => Order::PRODUCT_TYPES[$p] ?? $p)->implode(' / '),
                    'liters'      => $rows->sum('quantity'),
                    'required'    => $required ? Carbon::parse($required)->startOfDay() : null,
                    'has_date'    => (bool) $first->delivery_date,
                    'trip'        => $trip ? (object) [
                        'trip_no'    => $trip->trip_no,
                        'trip_date'  => Carbon::parse($trip->scheduled_date)->toDateString(),
                        'storage_tank_id' => $trip->storage_tank_id,
                    ] : null,
                ];
            })
            ->sortBy(fn ($job) => $job->required?->timestamp ?? PHP_INT_MAX)
            ->values();
    }

    /** Open deliveries that no tanker in Wet Stock has been given yet. */
    private function waiting(Collection $tankIds): Collection
    {
        return Delivery::query()
            ->join('orders as o', 'o.id', '=', 'deliveries.order_id')
            ->where('deliveries.status', '!=', 'CANCELLED')
            ->whereNull('deliveries.fulfilled_at')
            ->whereDoesntHave('allocations')
            ->select('deliveries.*', 'o.so_number', 'o.account', 'o.location', 'o.date_needed')
            ->orderByRaw('COALESCE(deliveries.delivery_date, o.date_needed)')
            ->limit(300)
            ->get()
            ->map(function (Delivery $delivery) {
                $required = $delivery->delivery_date ?: $delivery->date_needed;

                return (object) [
                    'delivery_id' => $delivery->id,
                    'dr'          => $delivery->dr_number,
                    'so'          => $delivery->so_number,
                    'client'      => $delivery->account,
                    'location'    => $delivery->location,
                    'products'    => Order::PRODUCT_TYPES[$delivery->product_type] ?? '-',
                    'liters'      => (int) $delivery->qty_out,
                    'required'    => $required ? Carbon::parse($required)->startOfDay() : null,
                    'has_date'    => (bool) $delivery->delivery_date,
                ];
            });
    }

    /** Paginate an already-built collection (the tabs are grouped in PHP). */
    private function paginate(Collection $items, int $perPage = 25): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();

        return new PaginatorInstance(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }
}
