<?php

namespace App\Imports;

use App\Models\Order;
use App\Models\Delivery;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class InventoryImport implements ToCollection, WithHeadingRow
{
    /**
     * Specify that the header row is the 2nd row in the Excel sheet.
     */
    public function headingRow(): int
    {
        return 2;
    }

    /**
     * Process the collection of rows.
     */
    public function collection(Collection $rows)
    {
        $currentOrder = null;

        foreach ($rows as $row) {
            // Maatwebsite Excel slugifies column headers:
            // "ACCOUNT" -> "account"
            // "DATE" -> "date"
            // "QTY ORDERED" -> "qty_ordered"
            // "SO#" -> "so"
            // "DR#" -> "dr"
            // "DELIVERY DATE" -> "delivery_date"
            // "QTY OUT" -> "qty_out"
            // "DELIVERY STATUS" -> "delivery_status"
            // "REMARKS" -> "remarks"

            $accountVal = $row['account'] ?? null;
            $soVal = $row['so'] ?? null;

            // If account is present and not empty, it indicates an Order row
            if (!empty($accountVal) && trim((string)$accountVal) !== '') {
                $orderDate = $this->parseDate($row['date'] ?? null);
                $qtyOrdered = isset($row['qty_ordered']) ? (int)$row['qty_ordered'] : 0;
                $soNumber = !empty($soVal) ? trim((string)$soVal) : ('SO-' . uniqid());

                // Find or create Order based on SO# (Merge and Update)
                $currentOrder = Order::updateOrCreate(
                    ['so_number' => $soNumber],
                    [
                        'account' => trim((string)$accountVal),
                        'date' => $orderDate,
                        'qty_ordered' => $qtyOrdered,
                    ]
                );
            }

            // Check if there is a delivery in this row (DR# is present)
            $drVal = $row['dr'] ?? null;
            if ($currentOrder && !empty($drVal) && trim((string)$drVal) !== '') {
                $drNumber = trim((string)$drVal);
                $deliveryDate = $this->parseDate($row['delivery_date'] ?? null);
                $qtyOut = isset($row['qty_out']) ? (int)$row['qty_out'] : 0;

                // Map status: FULFILLED is shown as DONE in excel export, so map DONE back to FULFILLED
                $statusRaw = strtoupper(trim((string)($row['delivery_status'] ?? 'PENDING')));
                if ($statusRaw === 'DONE') {
                    $status = 'FULFILLED';
                } elseif (in_array($statusRaw, ['PENDING', 'FULFILLED', 'CANCELLED'])) {
                    $status = $statusRaw;
                } else {
                    $status = 'PENDING';
                }

                $remarks = !empty($row['remarks']) ? trim((string)$row['remarks']) : null;

                // Find or create Delivery for this order by DR#
                $currentOrder->deliveries()->updateOrCreate(
                    ['dr_number' => $drNumber],
                    [
                        'delivery_date' => $deliveryDate,
                        'qty_out' => $qtyOut,
                        'status' => $status,
                        'remarks' => $remarks,
                    ]
                );
            }
        }
    }

    /**
     * Helper to parse date formats from Excel (serial numbers or string formats).
     */
    protected function parseDate($value)
    {
        if (empty($value)) {
            return Carbon::now();
        }

        // If it's a numeric Excel date serial number
        if (is_numeric($value)) {
            try {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
            } catch (\Exception $e) {
                // Fallback
            }
        }

        // If it's a string, try to parse it
        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return Carbon::now();
        }
    }
}
