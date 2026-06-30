<?php

namespace App\Exports;

use App\Models\Order;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class InventoryExport implements FromArray, WithHeadings, ShouldAutoSize
{
    /**
     * Return the array of data to be exported.
     */
    public function array(): array
    {
        $orders = Order::orderBy('date', 'desc')->get();
        $rows = [];

        foreach ($orders as $order) {
            $deliveries = $order->deliveries()->orderBy('delivery_date', 'asc')->get();
            $runningBalance = $order->qty_ordered;

            if ($deliveries->isEmpty()) {
                $rows[] = [
                    'ACCOUNT' => $order->account,
                    'DATE' => $order->date ? $order->date->format('Y-m-d') : '',
                    'QTY ORDERED' => $order->qty_ordered,
                    'SO#' => $order->so_number,
                    'DR#' => '',
                    'DELIVERY DATE' => '',
                    'QTY OUT' => '',
                    'DELIVERY BALANCE' => $runningBalance,
                    'DELIVERY STATUS' => '',
                    'REMARKS' => '',
                ];
            } else {
                foreach ($deliveries as $idx => $delivery) {
                    if ($delivery->status !== 'CANCELLED') {
                        $runningBalance -= $delivery->qty_out;
                    }

                    $statusMapped = $delivery->status === 'FULFILLED' ? 'DONE' : $delivery->status;

                    if ($idx === 0) {
                        $rows[] = [
                            'ACCOUNT' => $order->account,
                            'DATE' => $order->date ? $order->date->format('Y-m-d') : '',
                            'QTY ORDERED' => $order->qty_ordered,
                            'SO#' => $order->so_number,
                            'DR#' => $delivery->dr_number,
                            'DELIVERY DATE' => $delivery->delivery_date ? $delivery->delivery_date->format('Y-m-d') : '',
                            'QTY OUT' => $delivery->qty_out,
                            'DELIVERY BALANCE' => $runningBalance,
                            'DELIVERY STATUS' => $statusMapped,
                            'REMARKS' => $delivery->remarks ?? '',
                        ];
                    } else {
                        $rows[] = [
                            'ACCOUNT' => '',
                            'DATE' => '',
                            'QTY ORDERED' => '',
                            'SO#' => '',
                            'DR#' => $delivery->dr_number,
                            'DELIVERY DATE' => $delivery->delivery_date ? $delivery->delivery_date->format('Y-m-d') : '',
                            'QTY OUT' => $delivery->qty_out,
                            'DELIVERY BALANCE' => $runningBalance,
                            'DELIVERY STATUS' => $statusMapped,
                            'REMARKS' => $delivery->remarks ?? '',
                        ];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Define headings.
     */
    public function headings(): array
    {
        return [
            'ACCOUNT',
            'DATE',
            'QTY ORDERED',
            'SO#',
            'DR#',
            'DELIVERY DATE',
            'QTY OUT',
            'DELIVERY BALANCE',
            'DELIVERY STATUS',
            'REMARKS',
        ];
    }
}
