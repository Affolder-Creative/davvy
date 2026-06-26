<?php

namespace App\Services\Backups;

use App\Jobs\Backups\RunBackupRestoreOperationJob;
use App\Models\AppSetting;
use App\Services\Notifications\WebPushDispatchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BackupRestoreDispatchService
{
    private const LOCK_NAME = 'davvy-backup-restore-run';

    /** @var array<string, string> */
    private const SETTING_KEYS = [
        'operation_id' => 'backup_restore_operation_id',
        'status' => 'backup_restore_status',
        'reason' => 'backup_restore_reason',
        'mode' => 'backup_restore_mode',
        'dry_run' => 'backup_restore_dry_run',
        'queued_at_utc' => 'backup_restore_queued_at',
        'started_at_utc' => 'backup_restore_started_at',
        'finished_at_utc' => 'backup_restore_finished_at',
        'result_json' => 'backup_restore_result_json',
    ];

    public function __construct(
        private readonly BackupRestoreService $backupRestoreService,
        private readonly WebPushDispatchService $webPushDispatch,
    ) {}

    /**
     * @return array{
     *   status:'queued',
     *   operation_id:string,
     *   mode:'merge'|'replace',
     *   dry_run:bool,
     *   reason:string,
     *   queued_at_utc:string,
     *   started_at_utc:null,
     *   finished_at_utc:null,
     *   result:null
     * }
     */
    public function start(
        string $archivePath,
        string $mode,
        bool $dryRun,
        int $fallbackOwnerId,
        int $requestedByUserId,
        string $trigger = 'manual-admin',
    ): array {
        $lock = Cache::lock(self::LOCK_NAME, $this->restoreLockSeconds());
        if (! $lock->get()) {
            throw new RuntimeException('A backup restore is already in progress.');
        }
        $lockOwner = (string) $lock->owner();
        if ($lockOwner === '') {
            $lock->release();

            throw new RuntimeException('Unable to acquire backup restore lock owner token.');
        }

        $operationId = (string) Str::uuid();
        $stagedArchivePath = $this->stageArchive($archivePath, $operationId);
        $queuedAtUtc = now('UTC')->toIso8601String();

        $queuedPayload = [
            'status' => 'queued',
            'operation_id' => $operationId,
            'mode' => $mode,
            'dry_run' => $dryRun,
            'reason' => 'Backup restore queued and waiting to start.',
            'queued_at_utc' => $queuedAtUtc,
            'started_at_utc' => null,
            'finished_at_utc' => null,
            'result' => null,
        ];

        $this->persistStatus($queuedPayload, $requestedByUserId);

        try {
            RunBackupRestoreOperationJob::dispatch(
                stagedArchivePath: $stagedArchivePath,
                operationId: $operationId,
                queuedAtUtc: $queuedAtUtc,
                mode: $mode,
                dryRun: $dryRun,
                fallbackOwnerId: $fallbackOwnerId,
                requestedByUserId: $requestedByUserId,
                trigger: $trigger,
                lockOwner: $lockOwner,
            );
        } catch (Throwable $throwable) {
            if (is_file($stagedArchivePath)) {
                @unlink($stagedArchivePath);
            }

            $lock->release();

            throw $throwable;
        }

        return $queuedPayload;
    }

    /**
     * @return array{
     *   status:string,
     *   operation_id?:string,
     *   mode?:string,
     *   dry_run?:bool,
     *   reason?:string,
     *   queued_at_utc?:?string,
     *   started_at_utc?:?string,
     *   finished_at_utc?:?string,
     *   result?:array<string,mixed>|null
     * }
     */
    public function status(?string $operationId = null): array
    {
        $status = $this->readStatus();
        if ($status === null) {
            return ['status' => 'idle'];
        }

        if ($operationId !== null && $operationId !== '' && ($status['operation_id'] ?? '') !== $operationId) {
            return [
                'status' => 'not_found',
                'operation_id' => $operationId,
                'reason' => 'Backup restore operation was not found.',
                'result' => null,
            ];
        }

        return $status;
    }

    public function runQueuedOperation(
        string $stagedArchivePath,
        string $operationId,
        string $queuedAtUtc,
        string $mode,
        bool $dryRun,
        int $fallbackOwnerId,
        int $requestedByUserId,
        string $trigger,
        string $lockOwner,
    ): void {
        $lock = Cache::restoreLock(self::LOCK_NAME, $lockOwner);
        $startedAtUtc = now('UTC')->toIso8601String();

        $this->persistStatus([
            'status' => 'running',
            'operation_id' => $operationId,
            'mode' => $mode,
            'dry_run' => $dryRun,
            'reason' => 'Backup restore is running.',
            'queued_at_utc' => $queuedAtUtc,
            'started_at_utc' => $startedAtUtc,
            'finished_at_utc' => null,
            'result' => null,
        ], $requestedByUserId);

        try {
            $result = $this->backupRestoreService->restoreFromArchive(
                archivePath: $stagedArchivePath,
                mode: $mode,
                dryRun: $dryRun,
                fallbackOwnerId: $fallbackOwnerId,
                trigger: $trigger,
            );
            $finishedAtUtc = now('UTC')->toIso8601String();

            $this->persistStatus([
                'status' => (string) ($result['status'] ?? 'success'),
                'operation_id' => $operationId,
                'mode' => $mode,
                'dry_run' => $dryRun,
                'reason' => (string) ($result['reason'] ?? 'Backup restore completed.'),
                'queued_at_utc' => $queuedAtUtc,
                'started_at_utc' => $startedAtUtc,
                'finished_at_utc' => $finishedAtUtc,
                'result' => $result,
            ], $requestedByUserId);

            $this->webPushDispatch->notifyBackupOperationFinished(
                operation: 'restore',
                status: (string) ($result['status'] ?? 'success'),
                message: (string) ($result['reason'] ?? 'Backup restore completed.'),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            $finishedAtUtc = now('UTC')->toIso8601String();
            $failedReason = __('backups.restore_failed_reason', ['reason' => $throwable->getMessage()]);

            $this->persistStatus([
                'status' => 'failed',
                'operation_id' => $operationId,
                'mode' => $mode,
                'dry_run' => $dryRun,
                'reason' => $failedReason,
                'queued_at_utc' => $queuedAtUtc,
                'started_at_utc' => $startedAtUtc,
                'finished_at_utc' => $finishedAtUtc,
                'result' => [
                    'status' => 'failed',
                    'trigger' => $trigger,
                    'mode' => $mode,
                    'dry_run' => $dryRun,
                    'reason' => $failedReason,
                    'executed_at_utc' => $finishedAtUtc,
                    'manifest' => null,
                    'summary' => null,
                    'warnings' => [],
                ],
            ], $requestedByUserId);

            $this->webPushDispatch->notifyBackupOperationFinished(
                operation: 'restore',
                status: 'failed',
                message: $failedReason,
            );
        } finally {
            if (is_file($stagedArchivePath)) {
                @unlink($stagedArchivePath);
            }

            try {
                $lock->release();
            } catch (Throwable) {
                // No-op: lock may already be expired or released.
            }
        }
    }

    private function stageArchive(string $archivePath, string $operationId): string
    {
        $directory = storage_path('app/backups/restore-jobs');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('backups.unable_to_access_uploaded_archive'));
        }

        $stagedArchivePath = $directory.'/'.$operationId.'.zip';
        @unlink($stagedArchivePath);

        if (! @copy($archivePath, $stagedArchivePath) || ! is_file($stagedArchivePath)) {
            throw new RuntimeException(__('backups.unable_to_access_uploaded_archive'));
        }

        return $stagedArchivePath;
    }

    /**
     * @param  array{
     *   status:string,
     *   operation_id:string,
     *   mode:string,
     *   dry_run:bool,
     *   reason:string,
     *   queued_at_utc:?string,
     *   started_at_utc:?string,
     *   finished_at_utc:?string,
     *   result:array<string,mixed>|null
     * }  $payload
     */
    private function persistStatus(array $payload, int $requestedByUserId): void
    {
        $resultJson = null;
        if (is_array($payload['result'])) {
            $encoded = json_encode($payload['result'], JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $resultJson = $encoded;
            }
        }

        $this->setStatusValue(self::SETTING_KEYS['operation_id'], $payload['operation_id'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['status'], $payload['status'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['reason'], $payload['reason'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['mode'], $payload['mode'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['dry_run'], $payload['dry_run'] ? 'true' : 'false', $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['queued_at_utc'], $payload['queued_at_utc'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['started_at_utc'], $payload['started_at_utc'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['finished_at_utc'], $payload['finished_at_utc'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['result_json'], $resultJson, $requestedByUserId);
    }

    /**
     * @return array{
     *   status:string,
     *   operation_id:string,
     *   mode:string,
     *   dry_run:bool,
     *   reason:string,
     *   queued_at_utc:?string,
     *   started_at_utc:?string,
     *   finished_at_utc:?string,
     *   result:array<string,mixed>|null
     * }|null
     */
    private function readStatus(): ?array
    {
        $settingValues = AppSetting::query()
            ->whereIn('key', array_values(self::SETTING_KEYS))
            ->pluck('value', 'key')
            ->all();

        $status = trim((string) ($settingValues[self::SETTING_KEYS['status']] ?? ''));
        if ($status === '') {
            return null;
        }

        $result = null;
        $resultJson = $settingValues[self::SETTING_KEYS['result_json']] ?? null;
        if (is_string($resultJson) && trim($resultJson) !== '') {
            $decoded = json_decode($resultJson, true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }

        return [
            'status' => $status,
            'operation_id' => (string) ($settingValues[self::SETTING_KEYS['operation_id']] ?? ''),
            'mode' => (string) ($settingValues[self::SETTING_KEYS['mode']] ?? ''),
            'dry_run' => filter_var(
                $settingValues[self::SETTING_KEYS['dry_run']] ?? 'false',
                FILTER_VALIDATE_BOOLEAN,
            ),
            'reason' => (string) ($settingValues[self::SETTING_KEYS['reason']] ?? ''),
            'queued_at_utc' => $this->nullableString($settingValues[self::SETTING_KEYS['queued_at_utc']] ?? null),
            'started_at_utc' => $this->nullableString($settingValues[self::SETTING_KEYS['started_at_utc']] ?? null),
            'finished_at_utc' => $this->nullableString($settingValues[self::SETTING_KEYS['finished_at_utc']] ?? null),
            'result' => $result,
        ];
    }

    private function setStatusValue(string $key, ?string $value, int $requestedByUserId): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $requestedByUserId],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function restoreLockSeconds(): int
    {
        return max(60, (int) config('services.backups.restore_lock_seconds', 3600));
    }
}
