<?php

namespace App\Services;

use App\Models\User;

class DavRequestContext
{
    private ?User $authenticatedUser = null;

    private ?string $userAgent = null;

    /**
     * Sets authenticated user.
     */
    public function setAuthenticatedUser(User $user): void
    {
        $this->authenticatedUser = $user;
    }

    /**
     * Returns authenticated user.
     */
    public function getAuthenticatedUser(): ?User
    {
        return $this->authenticatedUser;
    }

    /**
     * Sets the DAV client user agent.
     */
    public function setUserAgent(?string $userAgent): void
    {
        $normalized = trim((string) ($userAgent ?? ''));

        $this->userAgent = $normalized !== '' ? $normalized : null;
    }

    /**
     * Returns the DAV client user agent.
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * Checks whether current request appears to be from an Apple contacts client.
     */
    public function isAppleContactsClient(): bool
    {
        $agent = $this->normalizedUserAgent();

        if ($agent === '') {
            return false;
        }

        return str_contains($agent, 'addressbook')
            || str_contains($agent, 'carddav')
            || str_contains($agent, 'dataaccessd');
    }

    /**
     * Checks whether current request appears to be from iOS contacts.
     */
    public function isAppleIosContactsClient(): bool
    {
        $agent = $this->normalizedUserAgent();

        if (! $this->isAppleContactsClient()) {
            return false;
        }

        return str_contains($agent, 'iphone')
            || str_contains($agent, 'ipad')
            || str_contains($agent, 'ipod')
            || str_contains($agent, 'ios/');
    }

    /**
     * Checks whether current request appears to be from macOS Contacts.
     */
    public function isAppleMacOsContactsClient(): bool
    {
        $agent = $this->normalizedUserAgent();

        if (! $this->isAppleContactsClient() || $this->isAppleIosContactsClient()) {
            return false;
        }

        return str_contains($agent, 'mac os x')
            || str_contains($agent, 'macos');
    }

    /**
     * Clears the value.
     */
    public function clear(): void
    {
        $this->authenticatedUser = null;
        $this->userAgent = null;
    }

    /**
     * Returns normalized user agent value.
     */
    private function normalizedUserAgent(): string
    {
        return strtolower(trim((string) ($this->userAgent ?? '')));
    }
}
