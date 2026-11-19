<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use Barryvdh\DomPDF\Facade\Pdf;

class DeliveryReceiptController extends Controller
{
    /**
     * Download the Delivery Receipt (PDF) for one delivery / D.R.
     */
    public function download(Delivery $delivery)
    {
        $delivery->load(['order', 'items']);

        $rows = [];
        foreach ($delivery->items as $item) {
            $rows[] = [
                'product' => $item->product_name,
                'qty' => (int) $item->qty_out,
            ];
        }

        // Older deliveries have no compartment lines: fall back to the delivery's own product/qty.
        if (empty($rows)) {
            $rows[] = [
                'product' => $delivery->product_name,
                'qty' => (int) $delivery->qty_out,
            ];
        }

        $logo = null;
        $logoPath = public_path('images/doyen-logo.png');
        if (is_file($logoPath)) {
            $logo = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
        }

        $pdf = Pdf::loadView('deliveries.delivery-receipt-pdf', [
            'delivery' => $delivery,
            'order' => $delivery->order,
            'rows' => $rows,
            'logo' => $logo,
        ])->setPaper('a5', 'landscape');

        $name = preg_replace('/[^A-Za-z0-9\-]+/', '-', (string) $delivery->dr_number);

        return $pdf->download('Delivery-Receipt-'.$name.'.pdf');
    }
}