<?php

it('displays the disclaimer page', function () {
    $response = $this->get('/disclaimer');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('Disclaimer');
    });
});

it('disclaimer route is accessible to everyone', function () {
    $response = $this->get('/disclaimer');

    $response->assertSuccessful();
});

it('can access disclaimer route name', function () {
    $response = $this->get(route('disclaimer'));

    $response->assertSuccessful();
});
