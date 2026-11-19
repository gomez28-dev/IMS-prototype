<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;

class SalesOrderReceiptController extends Controller
{
    /**
     * Download the Sales Order Receipt (PDF) for one order.
     */
    public function download(Order $order)
    {
        $order->load('items');

        $rows = [];
        foreach ($order->items as $item) {
            $rows[] = [
                'product' => $item->product_name,
                'qty' => (int) $item->qty,
                'price' => (float) $item->price,
                'amount' => (float) $item->amount,
            ];
        }

        // Older orders have no product lines: fall back to the order's own totals.
        if (empty($rows)) {
            $qty = (int) $order->qty_ordered;
            $price = (float) $order->price;
            $rows[] = [
                'product' => $order->resolvedProduct(),
                'qty' => $qty,
                'price' => $price,
                'amount' => (float) ($order->amount ?? ($qty * $price)),
            ];
        }

        // VAT INC. adds 12% on top of the product total; VAT EX. adds none.
        $subtotal = round((float) array_sum(array_column($rows, 'amount')), 2);
        $vatApplied = $order->vat_type === 'VAT_INC';
        $vatAmount = $vatApplied ? round($subtotal * Order::VAT_RATE, 2) : 0.0;
        $totalDue = round($subtotal + $vatAmount, 2);

        $logo = null;
        $logoPath = public_path('images/doyen-logo.png');
        if (is_file($logoPath)) {
            $logo = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
        }

        $pdf = Pdf::loadView('deliveries.receipt-pdf', [
            'order' => $order,
            'rows' => $rows,
            'totalQty' => array_sum(array_column($rows, 'qty')),
            'subtotal' => $subtotal,
            'vatApplied' => $vatApplied,
            'vatLabel' => $vatApplied ? 'VAT INC. (12%)' : 'VAT EX.',
            'vatAmount' => $vatAmount,
            'totalDue' => $totalDue,
            'logo' => $logo,
        ])->setPaper('a5', 'landscape');

        $name = preg_replace('/[^A-Za-z0-9\-]+/', '-', (string) $order->formatted_so_number);

        return $pdf->download('Sales-Order-Receipt-'.$name.'.pdf');
    }
}