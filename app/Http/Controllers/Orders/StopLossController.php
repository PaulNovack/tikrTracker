<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class StopLossController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Orders/StopLoss');
    }
}
