<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuickImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('investments/quick-import/index');
    }

    public function parse(Request $request)
    {
        $text = $request->input('text', '');

        // Parse the trade data
        $data = [
            'symbol' => null,
            'quantity' => null,
            'price_per_share' => null,
            'total_amount' => null,
            'transaction_date' => null,
        ];

        // Extract symbol (e.g., "AXS")
        if (preg_match('/(?:for a new |buy order for )([A-Z]{1,5})\s+position/i', $text, $matches)) {
            $data['symbol'] = strtoupper($matches[1]);
        } elseif (preg_match('/([A-Z]{1,5})\s+Units?:/i', $text, $matches)) {
            $data['symbol'] = strtoupper($matches[1]);
        }

        // Extract quantity
        if (preg_match('/Units?:\s*([0-9.,]+)/i', $text, $matches)) {
            $data['quantity'] = str_replace(',', '', $matches[1]);
        }

        // Extract price per share
        if (preg_match('/Unit Price:\s*\$([0-9.,]+)/i', $text, $matches)) {
            $data['price_per_share'] = str_replace(',', '', $matches[1]);
        }

        // Extract total amount
        if (preg_match('/(?:Invested Amount|Total):\s*\$([0-9.,]+)/i', $text, $matches)) {
            $data['total_amount'] = str_replace(',', '', $matches[1]);
        }

        // Extract date
        if (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $text, $matches)) {
            $data['transaction_date'] = date('Y-m-d\TH:i', strtotime($matches[1]));
        }

        return response()->json($data);
    }
}
