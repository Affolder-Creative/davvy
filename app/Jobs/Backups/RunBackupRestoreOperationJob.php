<?php

namespace App\Jobs\Backups;

use App\Services\Backups\BackupRestoreDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBackupRestoreOperationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly string $stagedArchivePath,
        private readonly string $operationId,
        private readonly string $queuedAtUtc,
        private readonly string $mode,
        private readonly bool $dryRun,
        private readonly int $fallbackOwnerId,
        private readonly int $requestedByUserId,
        private readonly string $trigger,
        private readonly string $lockOwner,
    ) {}

    public function handle(BackupRestoreDispatchService $dispatchService): void
    {
        $dispatchService->runQueuedOperation(
            stagedArchivePath: $this->stagedArchivePath,
            operationId: $this->operationId,
            queuedAtUtc: $this->queuedAtUtc,
            mode: $this->mode,
            dryRun: $this->dryRun,
            fallbackOwnerId: $this->fallbackOwnerId,
            requestedByUserId: $this->requestedByUserId,
            trigger: $this->trigger,
            lockOwner: $this->lockOwner,
        );
    }
}
