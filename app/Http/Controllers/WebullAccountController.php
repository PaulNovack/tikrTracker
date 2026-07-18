<?php

namespace App\Http\Controllers;

use App\Services\Webull\WebullTradingService;
use Inertia\Inertia;
use Inertia\Response;

class WebullAccountController
{
    public function positions(WebullTradingService $webull): Response
    {
        return Inertia::render('webull-positions/index', [
            'positions' => $webull->listAllPositions(),
        ]);
    }

    public function ordersToday(WebullTradingService $webull): Response
    {
        return Inertia::render('webull-orders/index', [
            'orders_today' => $webull->listAllTodayOrders(),
        ]);
    }

    public function openOrders(WebullTradingService $webull): Response
    {
        return Inertia::render('webull-open-orders/index', [
            'open_orders' => $webull->listAllOpenOrders(),
        ]);
    }
}
