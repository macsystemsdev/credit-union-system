<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // env() outside config/* returns null under config:cache. Use
        // app()->environment() so this survives production caching.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    public function register(): void
    {
        //
    }
}
