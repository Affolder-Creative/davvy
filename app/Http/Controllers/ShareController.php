<?php

namespace App\Http\Controllers;

use App\Enums\SharePermission;
use App\Enums\ShareResourceType;
use App\Models\AddressBook;
use App\Models\Calendar;
use App\Models\ResourceShare;
use App\Models\User;
use App\Services\AdminAuditLogService;
use App\Services\AddressBookMirrorService;
use App\Services\AddressBookPrivateWorkingSetService;
use App\Services\RegistrationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareController extends Controller
{
    public function __construct(
        private readonly RegistrationSettingsService $settings,
        private readonly AddressBookMirrorService $mirrorService,
        private readonly AddressBookPrivateWorkingSetService $privateWorkingSetService,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    /**
     * Lists resources.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'resource_type' => ['nullable', 'in:all,calendar,address_book'],
            'permission' => ['nullable', 'in:all,read_only,editor,admin'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'shared_with_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $resourceTypeFilter = (string) ($filters['resource_type'] ?? 'all');
        $permissionFilter = (string) ($filters['permission'] ?? 'all');
        $ownerIdFilter = array_key_exists('owner_id', $filters)
            ? (int) $filters['owner_id']
            : null;
        $sharedWithIdFilter = array_key_exists('shared_with_id', $filters)
            ? (int) $filters['shared_with_id']
            : null;
        $perPage = (int) ($filters['per_page'] ?? 100);
        $page = (int) ($filters['page'] ?? 1);

        $query = ResourceShare::query()
            ->with(['owner', 'sharedWith'])
            ->orderByDesc('id');

        if (! $user->isAdmin()) {
            $this->assertOwnerShareManagementAllowed();
            $query->where('owner_id', $user->id);
        } elseif ($ownerIdFilter !== null) {
            $query->where('owner_id', $ownerIdFilter);
        }

        if ($resourceTypeFilter === 'calendar') {
            $query->where('resource_type', ShareResourceType::Calendar->value);
        } elseif ($resourceTypeFilter === 'address_book') {
            $query->where('resource_type', ShareResourceType::AddressBook->value);
        }

        if ($permissionFilter !== 'all') {
            $query->where('permission', $permissionFilter);
        }

        if ($sharedWithIdFilter !== null) {
            $query->where('shared_with_id', $sharedWithIdFilter);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->whereHas('owner', function ($ownerQuery) use ($search): void {
                        $ownerQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('sharedWith', function ($sharedWithQuery) use ($search): void {
                        $sharedWithQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
            });
        }

        $paginator = $query
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends($request->query());

        $shares = $paginator
            ->getCollection()
            ->map(function (ResourceShare $share): array {
                return [
                    'id' => $share->id,
                    'resource_type' => $share->resource_type->value,
                    'resource_id' => $share->resource_id,
                    'permission' => $share->permission->value,
                    'owner' => [
                        'id' => $share->owner?->id,
                        'name' => $share->owner?->name,
                        'email' => $share->owner?->email,
                    ],
                    'shared_with' => [
                        'id' => $share->sharedWith?->id,
                        'name' => $share->sharedWith?->name,
                        'email' => $share->sharedWith?->email,
                    ],
                ];
            })
            ->all();

        return response()->json([
            'data' => $shares,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
            'filters' => [
                'q' => $search === '' ? null : $search,
                'resource_type' => $resourceTypeFilter,
                'permission' => $permissionFilter,
                'owner_id' => $ownerIdFilter,
                'shared_with_id' => $sharedWithIdFilter,
            ],
        ]);
    }

    /**
     * Creates or updates a resource.
     */
    public function upsert(Request $request): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'resource_type' => ['required', 'in:calendar,address_book'],
            'resource_id' => ['required', 'integer', 'min:1'],
            'shared_with_id' => ['required', 'integer', 'exists:users,id'],
            'permission' => ['required', 'in:read_only,editor,admin'],
        ]);

        $target = User::query()->findOrFail($data['shared_with_id']);

        $resourceType = ShareResourceType::from($data['resource_type']);
        [$resourceOwnerId, $isSharable] = $this->resourceOwnershipAndSharableState($resourceType, (int) $data['resource_id']);

        if (! $isSharable) {
            abort(422, __('shares.resource_must_be_sharable_before_assigning_access'));
        }

        if ($target->id === $resourceOwnerId) {
            abort(422, __('shares.cannot_share_with_owner'));
        }

        if (! $actor->isAdmin()) {
            $this->assertOwnerShareManagementAllowed();

            if ($resourceOwnerId !== $actor->id) {
                abort(403, __('shares.only_manage_own_resource_shares'));
            }
        }

        $existingShare = ResourceShare::query()->where([
            'resource_type' => $resourceType,
            'resource_id' => $data['resource_id'],
            'shared_with_id' => $target->id,
        ])->first();

        $share = ResourceShare::query()->updateOrCreate(
            [
                'resource_type' => $resourceType,
                'resource_id' => $data['resource_id'],
                'shared_with_id' => $target->id,
            ],
            [
                'owner_id' => $resourceOwnerId,
                'permission' => SharePermission::from($data['permission']),
            ]
        );

        if ($resourceType === ShareResourceType::AddressBook) {
            $this->mirrorService->syncUserConfig($target);
            $this->privateWorkingSetService->syncUserConfig($target);
        }

        $this->auditLog->record(
            actor: $actor,
            action: 'admin.share.upserted',
            context: [
                'operation' => $existingShare ? 'updated' : 'created',
                'share_id' => (int) $share->id,
                'resource_type' => $resourceType->value,
                'resource_id' => (int) $data['resource_id'],
                'owner_id' => (int) $resourceOwnerId,
                'shared_with_id' => (int) $target->id,
                'previous_permission' => $existingShare?->permission?->value,
                'permission' => $share->permission->value,
            ],
            request: $request,
        );

        return response()->json($share->fresh(), 201);
    }

    /**
     * Deletes an existing resource.
     */
    public function destroy(Request $request, ResourceShare $share): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->isAdmin()) {
            $this->assertOwnerShareManagementAllowed();

            if ($share->owner_id !== $actor->id) {
                abort(403, __('shares.only_remove_own_resource_shares'));
            }
        }

        $sharedWith = $share->sharedWith;
        $resourceType = $share->resource_type;
        $shareContext = [
            'share_id' => (int) $share->id,
            'resource_type' => $resourceType->value,
            'resource_id' => (int) $share->resource_id,
            'owner_id' => (int) $share->owner_id,
            'shared_with_id' => (int) $share->shared_with_id,
            'permission' => $share->permission->value,
        ];

        $share->delete();

        if ($resourceType === ShareResourceType::AddressBook && $sharedWith) {
            $this->mirrorService->syncUserConfig($sharedWith);
            $this->privateWorkingSetService->syncUserConfig($sharedWith);
        }

        $this->auditLog->record(
            actor: $actor,
            action: 'admin.share.deleted',
            context: $shareContext,
            request: $request,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Returns resource ownership and sharable state.
     */
    private function resourceOwnershipAndSharableState(ShareResourceType $type, int $resourceId): array
    {
        if ($type === ShareResourceType::Calendar) {
            $calendar = Calendar::query()->findOrFail($resourceId);

            return [$calendar->owner_id, $calendar->is_sharable];
        }

        $addressBook = AddressBook::query()->findOrFail($resourceId);

        return [$addressBook->owner_id, $addressBook->is_sharable];
    }

    /**
     * Asserts owner share management allowed.
     */
    private function assertOwnerShareManagementAllowed(): void
    {
        if (! $this->settings->isOwnerShareManagementEnabled()) {
            abort(403, __('shares.owner_share_management_disabled'));
        }
    }
}
