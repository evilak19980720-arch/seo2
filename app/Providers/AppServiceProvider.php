<?php

namespace App\Providers;

use App\Support\Media;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Media::configure();
        // Show the whole API error (e.g. "no credits remaining … add credits at …"), not its first 120 characters.
        RequestException::dontTruncate();
    }
}
