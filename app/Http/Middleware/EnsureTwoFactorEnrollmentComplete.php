<?php

namespace App\Http\Middleware;

use App\Services\Security\TwoFactorSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnrollmentComplete
{
    public function __construct(
        private readonly TwoFactorSettingsService $twoFactorSettings,
    ) {}

    /**
     * Handles the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->twoFactorSettings->isSetupRequired($user)) {
            return $next($request);
        }

        abort(423, __('auth.two_factor_setup_required_before_accessing_resource'));
    }
}
