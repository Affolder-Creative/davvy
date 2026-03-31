<?php

namespace App\Services\Dav\Backends;

use App\Models\User;
use App\Services\DavRequestContext;
use App\Services\PrincipalUriService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

class LaravelPrincipalBackend extends AbstractBackend
{
    private const DISPLAY_NAME_PROPERTY = '{DAV:}displayname';

    private const EMAIL_ADDRESS_PROPERTY = '{http://sabredav.org/ns}email-address';

    public function __construct(
        private readonly DavRequestContext $davContext,
        private readonly PrincipalUriService $principalUriService,
    ) {}

    /**
     * Returns principals matching a DAV prefix.
     *
     * @param  mixed  $prefixPath
     */
    public function getPrincipalsByPrefix($prefixPath): array
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        return User::query()
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => $this->transformUser($user))
            ->all();
    }

    /**
     * Returns a principal record for a DAV path.
     *
     * @param  mixed  $path
     */
    public function getPrincipalByPath($path): ?array
    {
        $user = $this->principalUriService->userFromPrincipalUri($path);

        if (! $user) {
            return null;
        }

        return $this->transformUser($user);
    }

    /**
     * Updates mutable principal properties.
     *
     * @param  mixed  $path
     */
    public function updatePrincipal($path, PropPatch $propPatch): void
    {
        $user = $this->principalUriService->userFromPrincipalUri($path);

        if (! $user) {
            return;
        }

        $authenticatedUser = $this->davContext->getAuthenticatedUser();
        if (! $authenticatedUser || (int) $authenticatedUser->id !== (int) $user->id) {
            $propPatch->setRemainingResultCode(403);

            return;
        }

        $propPatch->handle(
            [self::DISPLAY_NAME_PROPERTY, self::EMAIL_ADDRESS_PROPERTY],
            function (array $mutations) use ($user): array|bool {
                $attributes = [];

                if (array_key_exists(self::DISPLAY_NAME_PROPERTY, $mutations)) {
                    $attributes['name'] = trim((string) ($mutations[self::DISPLAY_NAME_PROPERTY] ?? ''));
                }

                if (array_key_exists(self::EMAIL_ADDRESS_PROPERTY, $mutations)) {
                    $attributes['email'] = Str::lower(
                        trim((string) ($mutations[self::EMAIL_ADDRESS_PROPERTY] ?? ''))
                    );
                }

                $validator = Validator::make($attributes, [
                    'name' => ['sometimes', 'string', 'max:255', 'filled'],
                    'email' => [
                        'sometimes',
                        'string',
                        'email',
                        'max:255',
                        Rule::unique('users', 'email')->ignore($user->id),
                    ],
                ]);

                if ($validator->fails()) {
                    return $this->validationResultCodes(
                        mutations: $mutations,
                        failedRules: $validator->failed(),
                    );
                }

                $emailChanged = array_key_exists('email', $attributes)
                    && $attributes['email'] !== Str::lower(trim((string) $user->email));

                $user->fill($attributes);
                if ($emailChanged) {
                    $user->email_verified_at = null;
                }
                if (! $user->isDirty()) {
                    return true;
                }

                try {
                    $user->save();
                } catch (QueryException $exception) {
                    if (
                        array_key_exists(self::EMAIL_ADDRESS_PROPERTY, $mutations)
                        && $this->isUniqueConstraintViolation($exception)
                    ) {
                        return $this->conflictResultCodes($mutations, self::EMAIL_ADDRESS_PROPERTY);
                    }

                    throw $exception;
                }

                return true;
            }
        );
    }

    /**
     * Searches principals by property criteria.
     *
     * @param  mixed  $prefixPath
     * @param  mixed  $test
     */
    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof'): array
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        $authenticatedUser = $this->davContext->getAuthenticatedUser();
        if (! $authenticatedUser) {
            return [];
        }

        $supportedProperties = [];
        if (array_key_exists(self::DISPLAY_NAME_PROPERTY, $searchProperties)) {
            $supportedProperties[] = [
                'column' => 'name',
                'value' => (string) $searchProperties[self::DISPLAY_NAME_PROPERTY],
            ];
        }

        if (array_key_exists(self::EMAIL_ADDRESS_PROPERTY, $searchProperties)) {
            $supportedProperties[] = [
                'column' => 'email',
                'value' => (string) $searchProperties[self::EMAIL_ADDRESS_PROPERTY],
            ];
        }

        if ($supportedProperties === []) {
            return [];
        }

        $query = User::query()->whereKey($authenticatedUser->id);
        if ($test === 'anyof') {
            $query->where(function ($builder) use ($supportedProperties): void {
                foreach ($supportedProperties as $index => $search) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}(
                        (string) $search['column'],
                        'like',
                        '%'.(string) $search['value'].'%',
                    );
                }
            });
        } else {
            foreach ($supportedProperties as $search) {
                $query->where(
                    (string) $search['column'],
                    'like',
                    '%'.(string) $search['value'].'%',
                );
            }
        }

        return $query->pluck('id')
            ->map(fn (int $id): string => 'principals/'.$id)
            ->all();
    }

    /**
     * Returns group members for a principal.
     *
     * @param  mixed  $principal
     */
    public function getGroupMemberSet($principal): array
    {
        return [];
    }

    /**
     * Returns groups containing the principal.
     *
     * @param  mixed  $principal
     */
    public function getGroupMembership($principal): array
    {
        return [];
    }

    /**
     * Updates group membership for a principal.
     *
     * @param  mixed  $principal
     */
    public function setGroupMemberSet($principal, array $members): void
    {
        // No groups in MVP.
    }

    /**
     * Returns transform user.
     */
    private function transformUser(User $user): array
    {
        return [
            'uri' => $this->principalUriService->uriForUser($user),
            self::DISPLAY_NAME_PROPERTY => $user->name,
            self::EMAIL_ADDRESS_PROPERTY => $user->email,
        ];
    }

    /**
     * Returns validation result codes for PROPPATCH mutations.
     *
     * @param  array<string, mixed>  $mutations
     * @param  array<string, array<string, array<int, mixed>>>  $failedRules
     * @return array<string, int>
     */
    private function validationResultCodes(array $mutations, array $failedRules): array
    {
        $propertyToField = [
            self::DISPLAY_NAME_PROPERTY => 'name',
            self::EMAIL_ADDRESS_PROPERTY => 'email',
        ];

        $results = [];
        foreach ($propertyToField as $property => $field) {
            if (! array_key_exists($property, $mutations) || ! isset($failedRules[$field])) {
                continue;
            }

            $results[$property] = $field === 'email' && array_key_exists('Unique', $failedRules[$field])
                ? 409
                : 422;
        }

        foreach ($propertyToField as $property => $field) {
            if (! array_key_exists($property, $mutations) || array_key_exists($property, $results)) {
                continue;
            }

            $results[$property] = 424;
        }

        return $results;
    }

    /**
     * Returns conflict result codes for PROPPATCH mutations.
     *
     * @param  array<string, mixed>  $mutations
     * @return array<string, int>
     */
    private function conflictResultCodes(array $mutations, string $failedProperty): array
    {
        $results = [];
        foreach ([self::DISPLAY_NAME_PROPERTY, self::EMAIL_ADDRESS_PROPERTY] as $property) {
            if (! array_key_exists($property, $mutations)) {
                continue;
            }

            $results[$property] = $property === $failedProperty ? 409 : 424;
        }

        return $results;
    }

    /**
     * Checks whether the query exception indicates a unique-index conflict.
     */
    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
