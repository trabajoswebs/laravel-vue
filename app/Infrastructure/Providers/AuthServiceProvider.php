<?php

namespace App\Infrastructure\Providers;

use App\Infrastructure\Models\User;
use App\Infrastructure\Auth\Policies\UserPolicy;
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
    }
}
