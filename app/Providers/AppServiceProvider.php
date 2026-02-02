<?php

namespace App\Providers;

use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        $this->app->register(RoutingServiceProvider::class);
    }
}
