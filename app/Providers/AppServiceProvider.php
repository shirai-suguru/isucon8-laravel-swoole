<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // DB::listen(function ($query) {
        //     Log::channel('sql')->debug('----- query time: ' . $query->time . ' [ms]------');
        //     Log::channel('sql')->debug($query->sql);
        //     Log::channel('sql')->debug($query->bindings);
        //     Log::channel('sql')->debug('-------------------------------------------');
        // });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
