<?php

namespace App\Providers;

use App\Models\Boat;
use App\Models\Rental;
use App\Models\Setting;
use App\Models\User;
use App\Observers\BoatObserver;
use App\Observers\RentalObserver;
use App\Observers\UserObserver;
use App\Policies\BoatPolicy;
use App\Policies\RentalPolicy;
use App\Policies\WorkerPolicy;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\ActivityLogService::class);
        $this->app->singleton(\App\Services\NotificationService::class);
        $this->app->singleton(\App\Services\TimerService::class);
        $this->app->singleton(\App\Services\BoatStatusService::class);
        $this->app->singleton(\App\Services\DashboardService::class);
        $this->app->singleton(\App\Services\BackupService::class);
        $this->app->singleton(\App\Services\ReportService::class);
        $this->app->singleton(\App\Services\ExportService::class);
    }

    public function boot(): void
    {
        // Force HTTPS on production/Render — prevents 419 CSRF errors on mobile
        // Render terminates SSL and sends X-Forwarded-Proto header
        $request = request();
        $isSecure = $request->isSecure()
            || $request->header('X-Forwarded-Proto') === 'https'
            || str_contains($request->getHttpHost(), 'onrender.com')
            || config('app.env') === 'production';

        if ($isSecure) {
            URL::forceScheme('https');
            // Force the config value so route(), url(), and @vite all use HTTPS
            $currentUrl = config('app.url');
            config(['app.url' => preg_replace('/^http:/', 'https:', $currentUrl)]);
            // Ensure session cookie is secure
            config(['session.secure' => true]);
            config(['session.domain' => null]); // Let browser auto-detect domain
        }

        // Load settings from DB and merge into brms config
        // This ensures saved settings (e.g. rental_duration_minutes) are used everywhere,
        // not just the .env defaults.
        try {
            $settings = Setting::pluck('value', 'key')->toArray();
            $brmsKeys = [
                'rental_duration_minutes',
                'warning_minutes',
                'alarm_interval_seconds',
                'session_timeout_minutes',
            ];
            foreach ($brmsKeys as $key) {
                if (isset($settings[$key])) {
                    config(["brms.{$key}" => (int) $settings[$key]]);
                }
            }
        } catch (\Exception $e) {
            // Settings table may not exist yet during migration — ignore
        }
        // Register observers
        Boat::observe(BoatObserver::class);
        Rental::observe(RentalObserver::class);
        User::observe(UserObserver::class);

        // Register policies
        Gate::policy(Boat::class, BoatPolicy::class);
        Gate::policy(Rental::class, RentalPolicy::class);
        Gate::policy(User::class, WorkerPolicy::class);

        // Rate limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email') . '|' . $request->ip());
        });
    }
}
