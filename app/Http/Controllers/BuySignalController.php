<?php

namespace App\Http\Controllers;

use App\Services\BuySignalsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BuySignalController extends Controller
{
    public function __construct(private BuySignalsService $buySignalsService) {}

    public function index(Request $request): \Inertia\Response
    {
        // Get simulated time from request or use current time
        $simTime = null;
        if ($request->has('time') && ! empty($request->get('time'))) {
            try {
                $simTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->get('time'), 'America/New_York');
            } catch (\Exception $e) {
                // Fall back to current time if invalid format
                $simTime = null;
            }
        }

        // Get buy signals with metadata
        $data = $this->buySignalsService->getBuySignalsWithMeta($simTime);

        return Inertia::render('buy-signals', [
            'signals' => $data,
            'time' => $request->get('time'),
        ]);
    }
}
