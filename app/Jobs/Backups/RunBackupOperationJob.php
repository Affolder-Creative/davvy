<?php

namespace App\Jobs\Backups;

use App\Services\Backups\BackupRunDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBackupOperationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly string $operationId,
        private readonly string $queuedAtUtc,
        private readonly int $requestedByUserId,
        private readonly string $trigger,
        private readonly string $lockOwner,
    ) {}

    public function handle(BackupRunDispatchService $dispatchService): void
    {
        $dispatchService->runQueuedOperation(
            operationId: $this->operationId,
            queuedAtUtc: $this->queuedAtUtc,
            requestedByUserId: $this->requestedByUserId,
            trigger: $this->trigger,
            lockOwner: $this->lockOwner,
        );
    }
}
