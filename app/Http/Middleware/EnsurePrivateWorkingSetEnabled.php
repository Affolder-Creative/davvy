<?php

namespace App\Http\Middleware;

use App\Services\RegistrationSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivateWorkingSetEnabled
{
    public function __construct(private readonly RegistrationSettingsService $settings) {}

    /**
     * Handles the incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->isPrivateWorkingSetEnabled()) {
            abort(403, __('contacts.private_working_set_disabled_by_admins'));
        }

        return $next($request);
    }
}
