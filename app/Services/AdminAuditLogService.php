<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class AdminAuditLogService
{
    /**
     * Records an admin-only audit event.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(User $actor, string $action, array $context = [], ?Request $request = null): void
    {
        if (! $actor->isAdmin()) {
            return;
        }

        $requestContext = $request;
        if ($requestContext === null && app()->bound('request')) {
            $boundRequest = app('request');
            if ($boundRequest instanceof Request) {
                $requestContext = $boundRequest;
            }
        }

        try {
            AdminAuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => trim($action),
                'ip_address' => $requestContext?->ip(),
                'user_agent' => $requestContext?->userAgent(),
                'context' => $context === [] ? null : $context,
                'created_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            // Audit logging should never block the primary action.
            report($throwable);
        }
    }
}
