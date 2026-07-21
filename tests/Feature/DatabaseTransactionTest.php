<?php

it('database transactions work properly in tests', function () {
    // Create a user in this test
    $user = \App\Models\User::factory()->create([
        'email' => 'test@example.com',
    ]);

    expect(\App\Models\User::where('email', 'test@example.com')->count())->toBe(1);
});

it('database transactions rollback properly between tests', function () {
    // This test should not see the user from the previous test
    expect(\App\Models\User::where('email', 'test@example.com')->count())->toBe(0);
});
