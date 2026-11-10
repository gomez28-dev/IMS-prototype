<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Logistics\Concerns\TankerData;
use App\Models\LogisticsTrip;
use App\Models\LogisticsTripStop;
use App\Models\LogisticsUnitStatus;
use App\Models\LogisticsUnitStatusLog;
use App\Services\Logistics\TripScheduling;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The office-maintained board of where each tanker is right now.
 *
 * A status that implies movement also advances that tanker's first open stop
 * (or the stop the dispatcher picked), so the Trip Schedule keeps up without
 * being updated twice.
 */
class UnitController extends Controller
{
    use TankerData;

    public function __construct(private readonly TripScheduling $scheduling)
    {
    }

    public function index(): View
    {
        $tankers = $this->tankers();
        $today = Carbon::today();

        $statuses = LogisticsUnitStatus::query()
            ->whereIn('storage_tank_id', $tankers->pluck('id'))
            ->with('updatedBy')
            ->get()
            ->keyBy('storage_tank_id');

        $trips = LogisticsTrip::query()
            ->whereIn('storage_tank_id', $tankers->pluck('id'))
            ->whereDate('scheduled_date', $today->toDateString())
            ->where('status', '!=', 'cancelled')
            ->with(['driver', 'helper', 'stops'])
            ->orderBy('id')
            ->get()
            ->groupBy('storage_tank_id');

        $cards = $tankers->map(function ($tanker) use ($statuses, $trips) {
            $row = $statuses->get($tanker->id);
            $mine = $trips->get($tanker->id, collect());
            $updated = $row?->changed_at ?? $row?->updated_at;

            $stops = $mine->flatMap(fn (LogisticsTrip $trip) => $trip->stops->map(fn (LogisticsTripStop $stop) => [
                'id'      => $stop->id,
                'name'    => $stop->client_name,
                'address' => $stop->address,
                'done'    => $stop->isDone(),
                'trip_no' => $trip->trip_no,
            ]))->values();

            $next = $stops->first(fn ($stop) => ! $stop['done']);

            return [
                'id'          => $tanker->id,
                'code'        => $tanker->code,
                'name'        => $tanker->name,
                'warehouse'   => $tanker->warehouse,
                'small'       => $tanker->small,
                'status'      => $row?->status,
                'label'       => $row ? LogisticsUnitStatus::label($row->status) : 'No update yet',
                'cls'         => $row ? $row->css_class : 'u-none',
                'icon'        => $row ? $row->icon : 'bi-question-circle',
                'target_type' => $row?->target_type,
                'target_name' => $row?->target_name,
                'note'        => $row?->note,
                'updated_label' => $updated
                    ? ($updated->isToday() ? 'Today ' . $updated->format('g:i A') : $updated->format('M j, g:i A'))
                    : null,
                'updated_ago' => $updated?->diffForHumans(),
                'updated_by'  => $row?->updatedBy?->name,
                'stale'       => ! $updated || ! $updated->isToday(),
                'driver'      => $mine->pluck('driver.name')->filter()->first(),
                'helper'      => $mine->pluck('helper.name')->filter()->first(),
                'trips'       => $mine->pluck('trip_no')->values(),
                'stops'       => $stops,
                'stops_done'  => $stops->where('done', true)->count(),
                'next_stop'   => $next['name'] ?? null,
            ];
        })->values();

        $summary = [
            'total'     => $cards->count(),
            'garage'    => $cards->where('status', 'garage')->count(),
            'maint'     => $cards->where('status', 'maintenance')->count(),
            'road'      => $cards->whereIn('status', ['to_supplier', 'to_client', 'to_next_drop'])->count(),
            'client'    => $cards->whereIn('status', ['waiting_unload', 'unloading'])->count(),
            'no_update' => $cards->where('stale', true)->count(),
        ];

        $logs = LogisticsUnitStatusLog::query()
            ->whereDate('created_at', $today->toDateString())
            ->with('updatedBy')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function (LogisticsUnitStatusLog $log) use ($tankers) {
                $tanker = $tankers->firstWhere('id', $log->storage_tank_id);

                return [
                    'time'   => $log->created_at?->format('g:i A'),
                    'code'   => $tanker->code ?? '-',
                    'small'  => $tanker->small ?? false,
                    'label'  => $log->label ?: LogisticsUnitStatus::label($log->status),
                    'target' => (string) ($log->target_name ?? ''),
                    'note'   => $log->note,
                    'by'     => $log->updatedBy?->name,
                ];
            });

        return view('logistics.units', [
            'cards'    => $cards,
            'summary'  => $summary,
            'logs'     => $logs,
            'statuses' => LogisticsUnitStatus::STATUSES,
            'canEdit'  => auth()->user()->canEditLogistics(),
        ]);
    }

    public function update(Request $request, int $tank)
    {
        abort_unless(auth()->user()->canEditLogistics(), 403,
            'Unauthorized: your role may view Logistics but not change it.');

        $tanker = $this->tankerModel($tank);

        $validated = $request->validate([
            'status'      => ['required', Rule::in(array_keys(LogisticsUnitStatus::STATUSES))],
            'stop_id'     => ['nullable', 'integer'],
            'target_name' => ['nullable', 'string', 'max:150'],
            'note'        => ['nullable', 'string', 'max:255'],
        ]);

        $status = $validated['status'];
        $targetType = LogisticsUnitStatus::needsTarget($status);
        $stop = null;

        if ($targetType && ! empty($validated['stop_id'])) {
            $stop = LogisticsTripStop::query()
                ->whereKey($validated['stop_id'])
                ->whereHas('trip', fn ($q) => $q->where('storage_tank_id', $tanker->id))
                ->first();

            if (! $stop) {
                return response()->json([
                    'message' => 'That drop-off does not belong to this tanker.',
                    'errors'  => ['stop_id' => ['That drop-off does not belong to this tanker.']],
                ], 422);
            }
        }

        $name = $validated['target_name'] ?? null;

        if ($targetType && ! $name && $stop) {
            $name = $stop->client_name;
        }

        if ($targetType && ! $name) {
            $label = $targetType === 'supplier' ? 'Supplier' : 'Client';

            return response()->json([
                'message' => "{$label} name is required for this status.",
                'errors'  => ['target_name' => ["{$label} name is required for this status."]],
            ], 422);
        }

        if (! $targetType) {
            $name = null;
            $stop = null;
        }

        $payload = [
            'status'      => $status,
            'target_type' => $targetType,
            'target_name' => $name,
            'note'        => $validated['note'] ?? null,
            'updated_by'  => auth()->id(),
            'changed_at'  => now('Asia/Manila'),
        ];

        LogisticsUnitStatus::updateOrCreate(['storage_tank_id' => $tanker->id], $payload);

        LogisticsUnitStatusLog::create([
            'storage_tank_id' => $tanker->id,
            'status'          => $status,
            'label'           => LogisticsUnitStatus::label($status),
            'target_type'     => $targetType,
            'target_name'     => $name,
            'note'            => $validated['note'] ?? null,
            'updated_by'      => auth()->id(),
            'created_at'      => now('Asia/Manila'),
        ]);

        $this->advanceStops($tanker->id, $status, $stop);

        return response()->json(['ok' => true]);
    }

    /**
     * The status the dispatcher picked becomes the current stop; anything
     * before it is completed through the service, so deliveries that should be
     * fulfilled still are.
     */
    private function advanceStops(int $tankerId, string $status, ?LogisticsTripStop $stop): void
    {
        $stopStatus = LogisticsUnitStatus::STOP_FOR[$status] ?? null;

        if (! $stopStatus) {
            return;
        }

        if (! $stop) {
            $trip = LogisticsTrip::query()
                ->where('storage_tank_id', $tankerId)
                ->whereDate('scheduled_date', Carbon::today()->toDateString())
                ->where('status', '!=', 'cancelled')
                ->whereHas('stops', fn ($q) => $q->where('status', '!=', LogisticsTripStop::DONE))
                ->orderByDesc('id')
                ->first();

            $stop = $trip?->stops()->where('status', '!=', LogisticsTripStop::DONE)->orderBy('sort')->first();
        }

        if (! $stop) {
            return;
        }

        $user = auth()->user();

        $stop->trip->stops()
            ->where('sort', '<', $stop->sort)
            ->where('status', '!=', LogisticsTripStop::DONE)
            ->orderBy('sort')
            ->get()
            ->each(fn (LogisticsTripStop $earlier) => $this->scheduling->setStopStatus($earlier, LogisticsTripStop::DONE, $user));

        $this->scheduling->setStopStatus($stop->fresh(), $stopStatus, $user);
    }
}
