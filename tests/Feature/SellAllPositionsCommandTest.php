<?php

use App\Services\AlpacaPythonService;

use function Pest\Laravel\mock;

it('retries stop-order cancellation before placing the liquidation sell', function () {
    $mockService = mock(AlpacaPythonService::class);

    $mockService->shouldReceive('runScript')
        ->once()
        ->with('get_positions.py')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                [
                    'symbol' => 'OSCR',
                    'qty' => '119',
                    'qty_available' => '0',
                ],
            ]),
            'error' => null,
        ]);

    $mockService->shouldReceive('cancelOrdersBySymbol')
        ->twice()
        ->with('OSCR')
        ->andReturn(
            ['success' => true, 'output' => json_encode(['success' => true, 'canceled_count' => 1]), 'error' => null],
            ['success' => true, 'output' => json_encode(['success' => true, 'canceled_count' => 0]), 'error' => null],
        );

    $mockService->shouldReceive('getOrders')
        ->twice()
        ->with('open', 500)
        ->andReturn(
            [
                'success' => true,
                'output' => json_encode([
                    'success' => true,
                    'count' => 1,
                    'orders' => [
                        [
                            'symbol' => 'OSCR',
                            'status' => 'accepted',
                            'qty' => '119',
                        ],
                    ],
                ]),
                'error' => null,
            ],
            [
                'success' => true,
                'output' => json_encode([
                    'success' => true,
                    'count' => 0,
                    'orders' => [],
                ]),
                'error' => null,
            ],
        );

    $mockService->shouldReceive('placeOrder')
        ->once()
        ->withArgs(function (mixed ...$args): bool {
            return ($args[0] ?? null) === 'OSCR'
                && ($args[1] ?? null) === 119.0
                && ($args[2] ?? null) === 'sell'
                && ($args[6] ?? null) === false
                && ($args[7] ?? null) === true;
        })
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'id' => 'sell-order-1',
                    'client_order_id' => 'sell-order-1',
                    'symbol' => 'OSCR',
                    'side' => 'sell',
                    'qty' => 119,
                    'filled_qty' => 119,
                    'filled_avg_price' => 29.10,
                    'order_type' => 'market',
                    'status' => 'filled',
                    'time_in_force' => 'day',
                ],
            ]),
            'error' => null,
        ]);

    $this->artisan('alpaca:sell-all-positions')
        ->expectsOutput('Selling all Alpaca positions...')
        ->expectsOutput('Found 1 position(s) to sell')
        ->expectsOutput('OSCR: Confirmed stop orders cancelled')
        ->expectsOutput('OSCR: Successfully placed sell order for 119 shares')
        ->assertExitCode(0);
});
