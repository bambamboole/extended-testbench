<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('renders a route in the browser', function () {
    Route::get('/dummy', fn () => 'Hello from extended-testbench');

    visit('/dummy')->assertSee('Hello from extended-testbench');
});
