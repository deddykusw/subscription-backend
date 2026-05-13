<?php

namespace App\Providers;

use App\Models\RenewalCheckout;
use App\Models\Subscription;
use App\Policies\RenewalCheckoutPolicy;
use App\Policies\SubscriptionPolicy;
use App\Support\RequestAttendanceToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    /**
     * Bootstrap application services.
     *
     * Policies are also auto-discovered by Laravel (App\Policies\{Model}Policy),
     * but explicit registration here makes the mapping visible and searchable.
     */
    public function boot(): void
    {
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(RenewalCheckout::class, RenewalCheckoutPolicy::class);

        RateLimiter::for('renewals-checkout', function (Request $request) {
            $token = RequestAttendanceToken::from($request) ?? '';
            $keyPart = $token !== '' ? hash('sha256', $token) : 'no-token';

            return Limit::perMinute((int) config('renewals.rate_limit.checkout_per_minute', 10))
                ->by($keyPart.'|'.$request->ip());
        });

        RateLimiter::for('renewals-proof', function (Request $request) {
            $token = RequestAttendanceToken::from($request) ?? '';
            $keyPart = $token !== '' ? hash('sha256', $token) : 'no-token';

            return Limit::perMinute((int) config('renewals.rate_limit.proof_per_minute', 8))
                ->by($keyPart.'|'.$request->ip());
        });
    }
}
