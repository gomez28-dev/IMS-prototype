<?php

namespace App\Http\Controllers\Logistics\Concerns;

use App\Models\LogisticsTripStop;
use App\Models\Order;
use App\Models\Personnel;
use App\Models\StorageTank;
use Illuminate\Support\Collection;

/**
 * Read-only option lists shared by the Logistics screens (Module 4).
 *
 * Tankers are returned as view objects rather than models because the board,
 * the job orders and the units page all want a short label ("T1", "ST2"), a
 * warehouse name and a flag for the small trucks — the prototype's shape,
 * minus its SHOW COLUMNS introspection.
 */
trait TankerData
{
    /**
     * Active tanker trucks with their compartments, labelled T1/T2… (regular)
     * or ST1/ST2… (any truck with "small" in its name).
     */
    protected function tankers(): Collection
    {
        $tanks = StorageTank::query()
            ->leftJoin('warehouses as w', 'w.id', '=', 'storage_tanks.warehouse_id')
            ->where('storage_tanks.is_active', true)
            ->where('storage_tanks.category', 'tanker')
            ->orderBy('storage_tanks.id')
            ->select('storage_tanks.*', 'w.name as warehouse_name')
            ->with('compartments')
            ->get();

        $counts = ['T' => 0, 'ST' => 0];

        return $tanks->map(function (StorageTank $tank) use (&$counts) {
            $small = stripos($tank->name, 'small') !== false;
            $prefix = $small ? 'ST' : 'T';

            return (object) [
                'id'           => $tank->id,
                'name'         => $tank->name,
                'code'         => $prefix . (++$counts[$prefix]),
                'small'        => $small,
                'warehouse'    => $tank->warehouse_name ?: '-',
                'max_capacity' => (int) $tank->max_capacity,
                'compartments' => $tank->compartments,
            ];
        })->values();
    }

    /** The model itself, for anything that has to write or validate. */
    protected function tankerModel(int $id): StorageTank
    {
        return StorageTank::query()
            ->where('category', 'tanker')
            ->with('compartments')
            ->findOrFail($id);
    }

    /** @return array{drivers: array<int,string>, helpers: array<int,string>} */
    protected function activePersonnel(): array
    {
        return [
            'drivers' => Personnel::active()->role('driver')->orderBy('name')->pluck('name', 'id')->all(),
            'helpers' => Personnel::active()->role('helper')->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /** Product code => name, e.g. ['D' => 'Diesel']. */
    protected function productOptions(): array
    {
        return Order::PRODUCT_TYPES;
    }

    /** Stop status code => label. */
    protected function stopStatusOptions(): array
    {
        return LogisticsTripStop::STATUSES;
    }
}
