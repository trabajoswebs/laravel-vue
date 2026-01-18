<?php

namespace App\Infrastructure\Providers;

use App\Infrastructure\Models\User;
use App\Infrastructure\Auth\Policies\UserPolicy;
use App\Infrastructure\Auth\Policies\UploadPolicy;
use App\Infrastructure\Uploads\Core\Models\Upload;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        Upload::class => UploadPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function ($user, string $ability) {
            if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
                return true;
            }

            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('override.policies')) {
                return true;
            }

            return null;
        });

        Gate::define('use-current-tenant', function (User $user): bool { // Habilidad para validar tenant activo
            return $user->can('useCurrentTenant', $user); // Delegado a UserPolicy@useCurrentTenant
        });
    }
}
