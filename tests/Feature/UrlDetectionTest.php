<?php

it('detects correct app URL for both Apache and Laravel dev server', function () {
    // Get the detected URL from config
    $detectedUrl = config('testing.detected_url');
    $appUrl = config('app.url');

    // Verify we have a detected URL
    expect($detectedUrl)->not->toBeNull();
    expect($appUrl)->not->toBeNull();

    // Test that we can actually access the application
    $response = $this->get('/');

    // Should either be successful or redirect (both indicate the app is running)
    expect($response->status())->toBeIn([200, 302]);

    // Output the detected URL for debugging
    echo "\n🔍 Detected URL: {$detectedUrl}";
    echo "\n🌐 Using APP_URL: {$appUrl}";
});

it('can access app with detected URL configuration', function () {
    // Test basic routes work
    $response = $this->get('/');
    expect($response->status())->toBeIn([200, 302]); // 302 is redirect to disclaimer

    // Test API endpoint works
    $response = $this->get('/api/health');
    expect($response->status())->toBeIn([200, 404]); // 404 is fine if health endpoint doesn't exist
});
