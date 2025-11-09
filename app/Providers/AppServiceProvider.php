<?php

namespace App\Providers;

use App\Observers\MediaObserver;
use App\Services\Upload\Core\LocalQuarantineRepository;
use App\Services\Upload\Core\QuarantineRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(QuarantineRepository::class, static function (): QuarantineRepository {
            return new LocalQuarantineRepository();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production')) {
            Model::preventLazyLoading();
        }

        Media::observe(MediaObserver::class);

        $this->app->afterResolving(Vite::class, function (Vite $vite, $app): void {
            $nonce = null;

            if ($app->bound('request')) {
                $request = $app['request'];

                if ($request && isset($request->attributes)) {
                    $nonce = $request->attributes->get('csp-nonce');
                }
            }

            if (is_string($nonce) && $nonce !== '') {
                $vite->useCspNonce($nonce);
            }
        });
    }
}
