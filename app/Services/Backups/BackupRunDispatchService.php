<?php

namespace App\Services\Backups;

use App\Jobs\Backups\RunBackupOperationJob;
use App\Models\AppSetting;
use App\Services\Notifications\WebPushDispatchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BackupRunDispatchService
{
    private const LOCK_NAME = 'davvy-backup-admin-run-dispatch';

    /** @var array<string, string> */
    private const SETTING_KEYS = [
        'operation_id' => 'backup_run_operation_id',
        'status' => 'backup_run_status',
        'reason' => 'backup_run_reason',
        'queued_at_utc' => 'backup_run_queued_at',
        'started_at_utc' => 'backup_run_started_at',
        'finished_at_utc' => 'backup_run_finished_at',
        'result_json' => 'backup_run_result_json',
    ];

    public function __construct(
        private readonly BackupService $backupService,
        private readonly WebPushDispatchService $webPushDispatch,
    ) {}

    /**
     * @return array{
     *   status:'queued',
     *   operation_id:string,
     *   reason:string,
     *   queued_at_utc:string,
     *   started_at_utc:null,
     *   finished_at_utc:null,
     *   result:null
     * }
     */
    public function start(int $requestedByUserId, string $trigger = 'manual-admin'): array
    {
        $lock = Cache::lock(self::LOCK_NAME, $this->dispatchLockSeconds());
        if (! $lock->get()) {
            throw new RuntimeException('A backup run is already in progress.');
        }
        $lockOwner = (string) $lock->owner();
        if ($lockOwner === '') {
            $lock->release();

            throw new RuntimeException('Unable to acquire backup run lock owner token.');
        }

        $operationId = (string) Str::uuid();
        $queuedAtUtc = now('UTC')->toIso8601String();

        $queuedPayload = [
            'status' => 'queued',
            'operation_id' => $operationId,
            'reason' => 'Backup run queued and waiting to start.',
            'queued_at_utc' => $queuedAtUtc,
            'started_at_utc' => null,
            'finished_at_utc' => null,
            'result' => null,
        ];

        $this->persistStatus($queuedPayload, $requestedByUserId);

        try {
            RunBackupOperationJob::dispatch(
                operationId: $operationId,
                queuedAtUtc: $queuedAtUtc,
                requestedByUserId: $requestedByUserId,
                trigger: $trigger,
                lockOwner: $lockOwner,
            );
        } catch (Throwable $throwable) {
            $lock->release();

            throw $throwable;
        }

        return $queuedPayload;
    }

    /**
     * @return array{
     *   status:string,
     *   operation_id?:string,
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
                'reason' => 'Backup run operation was not found.',
                'result' => null,
            ];
        }

        return $status;
    }

    public function runQueuedOperation(
        string $operationId,
        string $queuedAtUtc,
        int $requestedByUserId,
        string $trigger,
        string $lockOwner,
    ): void {
        $lock = Cache::restoreLock(self::LOCK_NAME, $lockOwner);
        $startedAtUtc = now('UTC')->toIso8601String();

        $this->persistStatus([
            'status' => 'running',
            'operation_id' => $operationId,
            'reason' => 'Backup run is running.',
            'queued_at_utc' => $queuedAtUtc,
            'started_at_utc' => $startedAtUtc,
            'finished_at_utc' => null,
            'result' => null,
        ], $requestedByUserId);

        try {
            $result = $this->backupService->run(force: true, trigger: $trigger);
            $finishedAtUtc = now('UTC')->toIso8601String();

            $this->persistStatus([
                'status' => (string) ($result['status'] ?? 'success'),
                'operation_id' => $operationId,
                'reason' => (string) ($result['reason'] ?? 'Backup run completed.'),
                'queued_at_utc' => $queuedAtUtc,
                'started_at_utc' => $startedAtUtc,
                'finished_at_utc' => $finishedAtUtc,
                'result' => $result,
            ], $requestedByUserId);

            $this->webPushDispatch->notifyBackupOperationFinished(
                operation: 'run',
                status: (string) ($result['status'] ?? 'success'),
                message: (string) ($result['reason'] ?? 'Backup run completed.'),
            );
        } catch (Throwable $throwable) {
            report($throwable);

            $finishedAtUtc = now('UTC')->toIso8601String();
            $failedReason = __('backups.backup_failed_reason', ['reason' => $throwable->getMessage()]);

            $this->persistStatus([
                'status' => 'failed',
                'operation_id' => $operationId,
                'reason' => $failedReason,
                'queued_at_utc' => $queuedAtUtc,
                'started_at_utc' => $startedAtUtc,
                'finished_at_utc' => $finishedAtUtc,
                'result' => [
                    'status' => 'failed',
                    'trigger' => $trigger,
                    'reason' => $failedReason,
                    'timezone' => AppSetting::backupTimezone(),
                    'executed_at_utc' => $finishedAtUtc,
                    'executed_at_local' => $finishedAtUtc,
                    'tiers' => [],
                    'artifact_count' => 0,
                    'artifacts' => [],
                    'resource_counts' => [
                        'calendars' => 0,
                        'address_books' => 0,
                        'calendar_objects' => 0,
                        'cards' => 0,
                        'skipped_malformed_objects' => 0,
                    ],
                ],
            ], $requestedByUserId);

            $this->webPushDispatch->notifyBackupOperationFinished(
                operation: 'run',
                status: 'failed',
                message: $failedReason,
            );
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // No-op: lock may already be expired or released.
            }
        }
    }

    /**
     * @param  array{
     *   status:string,
     *   operation_id:string,
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
        $this->setStatusValue(self::SETTING_KEYS['queued_at_utc'], $payload['queued_at_utc'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['started_at_utc'], $payload['started_at_utc'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['finished_at_utc'], $payload['finished_at_utc'], $requestedByUserId);
        $this->setStatusValue(self::SETTING_KEYS['result_json'], $resultJson, $requestedByUserId);
    }

    /**
     * @return array{
     *   status:string,
     *   operation_id:string,
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

    private function dispatchLockSeconds(): int
    {
        return max(60, (int) config('services.backups.restore_lock_seconds', 3600));
    }
}
