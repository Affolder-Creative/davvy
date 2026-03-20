<?php

namespace App\Http\Middleware;

use App\Services\RegistrationSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureContactManagementEnabled
{
    public function __construct(private readonly RegistrationSettingsService $settings) {}

    /**
     * Handles the incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->isContactManagementEnabled()) {
            abort(403, __('contacts.management_disabled_by_admins'));
        }

        return $next($request);
    }
}
