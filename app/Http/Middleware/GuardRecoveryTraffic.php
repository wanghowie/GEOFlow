<?php

namespace App\Http\Middleware;

use App\Services\SystemUpdater\RecoveryState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuardRecoveryTraffic
{
    public function __construct(private readonly RecoveryState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->state->assertHttpReady();
        if ($state !== null && $state['phase'] === 'http_ready' && ! $request->isMethodSafe()
            && ! $request->routeIs('admin.login.attempt', 'admin.logout', 'api.v1.auth.login', 'api.v1.auth.logout', 'api.v1.browser-session.logout', 'admin.system-updates.updater.*', 'api.v1.management.updater.*')) {
            // Read access and recovery controls remain available while restored work awaits reconciliation.
            $this->state->assertBackgroundReady();
        }

        return $next($request);
    }
}
