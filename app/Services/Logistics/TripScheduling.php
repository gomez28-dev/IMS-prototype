<?php

namespace App\Services\Logistics;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\LogisticsStopLine;
use App\Models\LogisticsTrip;
use App\Models\LogisticsTripStop;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Models\StorageTank;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to trips, stops, lines, crew and delivery fulfilment goes
 * through here (Module 4).
 *
 * Controllers validate HTTP input; this class owns the rules: capacity,
 * compartment/product matching, one crew per tanker per day, the delivery
 * date approval path, and marking a delivery fulfilled when its stop is done.
 */
class TripScheduling
{
    // ------------------------------------------------------------------
    // Trips
    // ------------------------------------------------------------------

    public function createTrip(array $data, Admin $user): LogisticsTrip
    {
        return DB::transaction(function () use ($data, $user) {
            $trip = LogisticsTrip::create([
                'storage_tank_id' => $data['storage_tank_id'],
                'trip_type'       => $data['trip_type'] ?? 'delivery',
                'status'          => 'scheduled',
                'scheduled_date'  => $data['scheduled_date'],
                'supplier_name'   => $data['supplier_name'] ?? null,
                'driver_id'       => $data['driver_id'] ?? null,
                'helper_id'       => $data['helper_id'] ?? null,
                'remarks'         => $data['remarks'] ?? null,
                'created_by'      => $user->id,
                'updated_by'      => $user->id,
            ]);

            $this->assignTripNo($trip);
            $this->syncCrew($trip, $user);
            $this->audit($user, 'created', "Created trip {$trip->trip_no} for {$trip->tanker->name}");

            return $trip->fresh(['tanker', 'stops.lines']);
        });
    }

    public function updateTrip(LogisticsTrip $trip, array $data, Admin $user): LogisticsTrip
    {
        return DB::transaction(function () use ($trip, $data, $user) {
            $trip->fill([
                'scheduled_date' => $data['scheduled_date'] ?? $trip->scheduled_date,
                'trip_type'      => $data['trip_type'] ?? $trip->trip_type,
                'supplier_name'  => $data['supplier_name'] ?? null,
                'driver_id'      => array_key_exists('driver_id', $data) ? $data['driver_id'] : $trip->driver_id,
                'helper_id'      => array_key_exists('helper_id', $data) ? $data['helper_id'] : $trip->helper_id,
                'remarks'        => $data['remarks'] ?? null,
                'updated_by'     => $user->id,
            ])->save();

            $this->syncCrew($trip, $user);
            $this->audit($user, 'updated', "Updated trip {$trip->trip_no}");

            return $trip->fresh(['tanker', 'stops.lines']);
        });
    }

    /** Cancelling is a status change; the record of the trip is kept. */
    public function cancelTrip(LogisticsTrip $trip, Admin $user): void
    {
        if ($trip->hasCompletedStop()) {
            throw ValidationException::withMessages([
                'trip' => "Trip {$trip->trip_no} already delivered a load, so it cannot be cancelled.",
            ]);
        }

        $trip->update(['status' => 'cancelled', 'updated_by' => $user->id]);

        $this->audit($user, 'cancelled', "Cancelled trip {$trip->trip_no} for {$trip->tanker->name}");
    }

    // ------------------------------------------------------------------
    // Stops and lines
    // ------------------------------------------------------------------

    public function addStop(LogisticsTrip $trip, array $data, Admin $user): LogisticsTripStop
    {
        return DB::transaction(function () use ($trip, $data, $user) {
            $this->assertTripOpen($trip);
            $trip->loadMissing('tanker');

            $deliveryId = $data['delivery_id'] ?? null;

            if ($deliveryId && $trip->stops()->where('delivery_id', $deliveryId)->exists()) {
                throw ValidationException::withMessages([
                    'delivery_id' => 'That delivery is already on this trip.',
                ]);
            }

            $lines = $this->validateLines($trip->tanker, $data['lines'] ?? []);
            $this->assertCapacity($trip->tanker, $trip->scheduled_date, $lines, null, $deliveryId);

            $stop = $trip->stops()->create([
                'sort'          => (int) $trip->stops()->max('sort') + 1,
                'client_name'   => $data['client_name'],
                'address'       => $data['address'] ?? null,
                'so_no'         => $data['so_no'] ?? null,
                'dr_no'         => $data['dr_no'] ?? null,
                'wb_no'         => $data['wb_no'] ?? null,
                'delivery_id'   => $deliveryId,
                'order_id'      => $data['order_id'] ?? null,
                'status'        => $data['status'] ?? 'pending',
                'status_eta'    => $data['status_eta'] ?? null,
            ]);

            foreach ($lines as $line) {
                $stop->lines()->create($line);
            }

            $this->syncTripState($trip, $user);
            $this->audit($user, 'created', "Added stop {$stop->client_name} to trip {$trip->trip_no}");

            return $stop->fresh(['lines', 'delivery']);
        });
    }

    public function updateStop(LogisticsTripStop $stop, array $data, Admin $user): LogisticsTripStop
    {
        return DB::transaction(function () use ($stop, $data, $user) {
            $trip = $stop->trip;
            $this->assertTripOpen($trip);
            $trip->loadMissing('tanker');

            $lines = $this->validateLines($trip->tanker, $data['lines'] ?? []);
            $this->assertCapacity($trip->tanker, $trip->scheduled_date, $lines, $stop->id, $stop->delivery_id);

            $stop->update([
                'client_name' => $data['client_name'] ?? $stop->client_name,
                'address'     => $data['address'] ?? null,
                'so_no'       => $data['so_no'] ?? null,
                'dr_no'       => $data['dr_no'] ?? null,
                'wb_no'       => $data['wb_no'] ?? null,
                'order_id'    => $data['order_id'] ?? $stop->order_id,
                'status_eta'  => $data['status_eta'] ?? null,
            ]);

            $stop->lines()->delete();
            foreach ($lines as $line) {
                $stop->lines()->create($line);
            }

            $this->syncTripState($trip, $user);
            $this->audit($user, 'updated', "Updated stop {$stop->client_name} on trip {$trip->trip_no}");

            return $stop->fresh(['lines', 'delivery']);
        });
    }

    public function deleteStop(LogisticsTripStop $stop, Admin $user): void
    {
        DB::transaction(function () use ($stop, $user) {
            $trip = $stop->trip;
            $this->assertTripOpen($trip);

            $stop->delete();
            $this->resequence($trip);
            $this->syncTripState($trip, $user);

            $this->audit($user, 'deleted', "Removed stop {$stop->client_name} from trip {$trip->trip_no}");
        });
    }

    public function setStopStatus(LogisticsTripStop $stop, string $status, Admin $user, ?string $eta = null): void
    {
        if (! array_key_exists($status, LogisticsTripStop::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Unknown stop status.']);
        }

        DB::transaction(function () use ($stop, $status, $user, $eta) {
            $this->assertTripOpen($stop->trip);

            $stop->update([
                'status'     => $status,
                'status_eta' => $eta,
                'done_at'    => $status === LogisticsTripStop::DONE ? now('Asia/Manila') : null,
            ]);

            $this->syncTripState($stop->trip, $user);
            $this->fulfilIfDone($stop, $user);

            $this->audit($user, 'updated',
                "Stop {$stop->client_name} on trip {$stop->trip->trip_no} is now " . LogisticsTripStop::STATUSES[$status]);
        });
    }

    // ------------------------------------------------------------------
    // Job Orders
    // ------------------------------------------------------------------

    /**
     * Turn a Wet Stock delivery into a trip stop, creating the trip when the
     * tanker has none for that day. If the delivery sits on another date, the
     * move is *requested* — never written here.
     */
    public function scheduleFromDelivery(Delivery $delivery, StorageTank $tanker, Carbon $date, Admin $user, array $crew = []): LogisticsTrip
    {
        $allocations = $delivery->allocations()->where('storage_tank_id', $tanker->id)->get();

        if ($allocations->isEmpty()) {
            throw ValidationException::withMessages([
                'delivery' => 'This delivery has no allocation on that tanker.',
            ]);
        }

        if ($allocations->contains(fn (DeliveryAllocation $a) => ! $a->tank_compartment_id)) {
            throw ValidationException::withMessages([
                'delivery' => 'This delivery has no compartment allocated — assign one in Wet Stock first.',
            ]);
        }

        return DB::transaction(function () use ($delivery, $tanker, $date, $user, $allocations, $crew) {
            $trip = LogisticsTrip::query()
                ->where('storage_tank_id', $tanker->id)
                ->whereDate('scheduled_date', $date)
                ->where('trip_type', 'delivery')
                ->where('status', '!=', 'cancelled')
                ->first();

            if (! $trip) {
                $trip = LogisticsTrip::create([
                    'storage_tank_id' => $tanker->id,
                    'trip_type'       => 'delivery',
                    'status'          => 'scheduled',
                    'scheduled_date'  => $date,
                    'created_by'      => $user->id,
                    'updated_by'      => $user->id,
                ]);

                $this->assignTripNo($trip);
                $this->audit($user, 'created', "Created trip {$trip->trip_no} for {$trip->tanker->name} from Job Orders");
            }

            // Crew chosen in the Job Orders form wins; otherwise the trip keeps
            // whatever the day already has.
            if ($crew !== []) {
                $trip->fill($crew)->save();
                $this->syncCrew($trip, $user);
            }

            $order = $delivery->order;

            $lines = $allocations->map(fn (DeliveryAllocation $a) => [
                'compartment_no' => (int) $a->compartment->compartment_no,
                'product_type'   => $a->compartment->product_type ?: $delivery->product_type,
                'quantity'       => (int) $a->quantity,
            ])->all();

            $this->addStop($trip, [
                'client_name' => $order?->account ?: 'Client',
                'address'     => $order?->location,
                'so_no'       => $order?->so_number,
                'dr_no'       => $delivery->dr_number,
                'delivery_id' => $delivery->id,
                'order_id'    => $delivery->order_id,
                'lines'       => $lines,
            ], $user);

            $old = $delivery->delivery_date?->format('Y-m-d');
            if ($old && $old !== $date->format('Y-m-d')) {
                $this->requestDateChange(
                    $delivery,
                    $date->format('Y-m-d'),
                    "Rescheduled from Logistics — trip {$trip->trip_no}",
                    $user
                );
            }

            return $trip->fresh(['tanker', 'stops.lines']);
        });
    }

    /**
     * Logistics never writes deliveries.delivery_date. This creates the
     * Module 1 approval request; the date moves when admin/hod approve it.
     */
    public function requestDateChange(Delivery $delivery, string $newDate, string $reason, Admin $user): ModificationRequest
    {
        $alreadyPending = ModificationRequest::query()
            ->where('requestable_type', Delivery::class)
            ->where('requestable_id', $delivery->id)
            ->where('status', 'PENDING')
            ->get()
            ->contains(fn (ModificationRequest $r) => isset($r->changes['delivery_date']));

        if ($alreadyPending) {
            throw ValidationException::withMessages([
                'delivery_date' => 'A date change for this delivery is already waiting for approval.',
            ]);
        }

        $old = $delivery->delivery_date?->format('Y-m-d');

        if ($old === $newDate) {
            throw ValidationException::withMessages(['delivery_date' => 'That is already the delivery date.']);
        }

        $request = ModificationRequest::create([
            'requestable_type' => Delivery::class,
            'requestable_id'   => $delivery->id,
            'requested_by'     => $user->id,
            'changes'          => ['delivery_date' => ['old' => $old, 'new' => $newDate]],
            'reason'           => $reason,
            'status'           => 'PENDING',
        ]);

        $this->audit($user, 'requested',
            "Requested delivery #{$delivery->id} move {$old} → {$newDate} from Logistics");

        return $request;
    }

    // ------------------------------------------------------------------
    // Capacity and line validation
    // ------------------------------------------------------------------

    /**
     * Litres already committed to each compartment on a tanker for a date:
     * trip stop lines plus Wet Stock allocations.
     *
     * @return array<int,int> compartment_no => litres
     */
    public function plannedLitres(
        StorageTank $tanker,
        Carbon $date,
        ?int $excludeStopId = null,
        ?int $excludeDeliveryId = null
    ): array {
        $planned = [];

        $lineTotals = LogisticsStopLine::query()
            ->join('logistics_trip_stops as s', 's.id', '=', 'logistics_stop_lines.logistics_trip_stop_id')
            ->join('logistics_trips as t', 't.id', '=', 's.logistics_trip_id')
            ->where('t.storage_tank_id', $tanker->id)
            ->whereDate('t.scheduled_date', $date)
            ->where('t.status', '!=', 'cancelled')
            ->when($excludeStopId, fn ($q) => $q->where('s.id', '!=', $excludeStopId))
            ->groupBy('logistics_stop_lines.compartment_no')
            ->selectRaw('logistics_stop_lines.compartment_no as no, SUM(logistics_stop_lines.quantity) as total')
            ->pluck('total', 'no');

        foreach ($lineTotals as $no => $total) {
            $planned[(int) $no] = (int) $total;
        }

        $allocated = DeliveryAllocation::query()
            ->join('deliveries as d', 'd.id', '=', 'delivery_allocations.delivery_id')
            ->join('tank_compartments as c', 'c.id', '=', 'delivery_allocations.tank_compartment_id')
            ->where('delivery_allocations.storage_tank_id', $tanker->id)
            ->whereDate('d.delivery_date', $date)
            ->where('d.status', '!=', 'CANCELLED')
            ->when($excludeDeliveryId, fn ($q) => $q->where('d.id', '!=', $excludeDeliveryId))
            ->groupBy('c.compartment_no')
            ->selectRaw('c.compartment_no as no, SUM(delivery_allocations.quantity) as total')
            ->pluck('total', 'no');

        foreach ($allocated as $no => $total) {
            $planned[(int) $no] = ($planned[(int) $no] ?? 0) + (int) $total;
        }

        return $planned;
    }

    /**
     * @param  array<int,array{compartment_no:int,quantity:int}>  $lines
     */
    public function assertCapacity(
        StorageTank $tanker,
        Carbon $date,
        array $lines,
        ?int $excludeStopId = null,
        ?int $excludeDeliveryId = null
    ): void {
        $planned = $this->plannedLitres($tanker, $date, $excludeStopId, $excludeDeliveryId);
        $compartments = $tanker->compartments()->get()->keyBy('compartment_no');

        $requested = [];
        foreach ($lines as $line) {
            $no = (int) $line['compartment_no'];
            $requested[$no] = ($requested[$no] ?? 0) + (int) $line['quantity'];
        }

        foreach ($requested as $no => $litres) {
            $capacity = (int) ($compartments[$no]->capacity ?? 0);
            $total = ($planned[$no] ?? 0) + $litres;

            if ($capacity > 0 && $total > $capacity) {
                throw ValidationException::withMessages([
                    'lines' => "Compartment {$no} would hold {$total} L against a {$capacity} L limit — over by "
                        . number_format($total - $capacity) . ' L.',
                ]);
            }
        }
    }

    /**
     * Compartment must exist on the tanker, product must be a real code, and a
     * product-tagged compartment only accepts that product (the prototype
     * compared full names against codes and failed here).
     *
     * @return array<int,array{compartment_no:int,product_type:?string,quantity:int}>
     */
    private function validateLines(StorageTank $tanker, array $lines): array
    {
        if (empty($lines)) {
            throw ValidationException::withMessages(['lines' => 'A stop needs at least one compartment line.']);
        }

        $compartments = $tanker->compartments()->get()->keyBy('compartment_no');
        $clean = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $no = (int) ($line['compartment_no'] ?? 0);
            $product = $line['product_type'] ?? null;
            $quantity = (int) ($line['quantity'] ?? 0);

            if (! $compartments->has($no)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.compartment_no" => "Compartment {$no} is not on {$tanker->name}.",
                ]);
            }

            if ($product !== null && $product !== '' && ! array_key_exists($product, Order::PRODUCT_TYPES)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_type" => 'Choose Diesel, Unleaded or Premium.',
                ]);
            }

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => 'Quantity must be more than zero litres.',
                ]);
            }

            if (isset($seen[$no])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.compartment_no" => "Compartment {$no} appears twice in one stop.",
                ]);
            }
            $seen[$no] = true;

            $compartment = $compartments[$no];

            if ($compartment->product_type && $product && $compartment->product_type !== $product) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product_type" => "Compartment {$no} is tagged "
                        . (Order::PRODUCT_TYPES[$compartment->product_type] ?? $compartment->product_type)
                        . ', so it cannot carry ' . (Order::PRODUCT_TYPES[$product] ?? $product) . '.',
                ]);
            }

            // First line into an untagged compartment sets its product, so the
            // Wet Stock screens and this board agree from then on.
            if (! $compartment->product_type && $product) {
                $compartment->update(['product_type' => $product]);
            }

            $clean[] = [
                'compartment_no' => $no,
                'product_type'   => $product ?: $compartment->product_type,
                'quantity'       => $quantity,
            ];
        }

        return $clean;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    public function assignTripNo(LogisticsTrip $trip): void
    {
        $trip->forceFill([
            'trip_no' => sprintf('TRP-%s-%d', $trip->scheduled_date->format('ymd'), $trip->id),
        ])->save();
    }

    /**
     * One tanker, one crew per day: a crew change applies to that day's trips.
     *
     * Only values that are actually set propagate — adding a pick-up trip that
     * names no crew must not wipe the driver already on that day's delivery trip.
     */
    public function syncCrew(LogisticsTrip $trip, Admin $user): void
    {
        $changes = array_filter([
            'driver_id' => $trip->driver_id,
            'helper_id' => $trip->helper_id,
        ], fn ($value) => $value !== null);

        if (empty($changes)) {
            return;
        }

        LogisticsTrip::query()
            ->where('storage_tank_id', $trip->storage_tank_id)
            ->whereDate('scheduled_date', $trip->scheduled_date)
            ->whereKeyNot($trip->id)
            ->update($changes + [
                'updated_by' => $user->id,
                'updated_at' => now('Asia/Manila'),
            ]);
    }

    /** Trip lifecycle follows its stops. */
    public function syncTripState(LogisticsTrip $trip, Admin $user): void
    {
        if ($trip->status === 'cancelled') {
            return;
        }

        $stops = $trip->stops()->get();

        if ($stops->isEmpty()) {
            $trip->update([
                'status'       => 'scheduled',
                'started_at'   => null,
                'completed_at' => null,
                'updated_by'   => $user->id,
            ]);

            return;
        }

        $allDone = $stops->every(fn (LogisticsTripStop $s) => $s->isDone());
        $started = $stops->contains(fn (LogisticsTripStop $s) => $s->status !== 'pending');

        $trip->update([
            'status'       => $allDone ? 'completed' : 'scheduled',
            'started_at'   => $started ? ($trip->started_at ?? now('Asia/Manila')) : null,
            'completed_at' => $allDone ? ($trip->completed_at ?? now('Asia/Manila')) : null,
            'updated_by'   => $user->id,
        ]);
    }

    /**
     * Only a role that may mark a delivery fulfilled does so here. An editor
     * without that permission still completes the stop; the delivery stays
     * PENDING for an authorised user to finish in Module 2.
     */
    private function fulfilIfDone(LogisticsTripStop $stop, Admin $user): void
    {
        if (! $stop->isDone() || ! $stop->delivery_id || ! $user->canMarkFulfilled()) {
            return;
        }

        $delivery = $stop->delivery;

        if (! $delivery || $delivery->status === 'FULFILLED') {
            return;
        }

        $delivery->update([
            'status'       => 'FULFILLED',
            'fulfilled_by' => $user->id,
            'fulfilled_at' => now('Asia/Manila'),
        ]);

        $this->audit($user, 'fulfilled',
            "Trip {$stop->trip->trip_no} completed stop for {$stop->client_name} — delivery #{$delivery->id} marked FULFILLED");
    }

    /** A cancelled trip is a record, not a plan — nothing may change on it. */
    private function assertTripOpen(LogisticsTrip $trip): void
    {
        if ($trip->status === 'cancelled') {
            throw ValidationException::withMessages([
                'trip' => "Trip {$trip->trip_no} is cancelled, so its stops cannot be changed.",
            ]);
        }
    }

    private function resequence(LogisticsTrip $trip): void
    {
        $trip->stops()->orderBy('sort')->orderBy('id')->get()
            ->each(fn (LogisticsTripStop $stop, int $i) => $stop->update(['sort' => $i + 1]));
    }

    private function audit(Admin $user, string $action, string $description): void
    {
        AuditLog::create([
            'admin_id'    => $user->id,
            'action'      => $action,
            'description' => $description,
        ]);
    }
}
