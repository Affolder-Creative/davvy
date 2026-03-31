<?php

namespace App\Services\Backups;

use Illuminate\Support\Str;

class BackupResourceUriService
{
    /**
     * Returns next unique resource URI.
     *
     * @param  array<int, string>  $uriPool
     */
    public function nextUniqueResourceUri(
        string $candidate,
        string $extension,
        string $fallbackStem,
        array &$uriPool,
    ): string {
        $normalized = $this->normalizeResourceUri($candidate, $extension, $fallbackStem);
        $base = pathinfo($normalized, PATHINFO_FILENAME);
        $ext = pathinfo($normalized, PATHINFO_EXTENSION);

        $next = $normalized;
        $counter = 2;
        while (in_array($next, $uriPool, true)) {
            $next = sprintf('%s-%d.%s', $base, $counter, $ext);
            $counter++;
        }

        $uriPool[] = $next;

        return $next;
    }

    /**
     * Normalizes resource URI.
     */
    public function normalizeResourceUri(string $candidate, string $extension, string $fallbackStem): string
    {
        $stem = Str::slug(pathinfo(trim($candidate), PATHINFO_FILENAME));
        if ($stem === '') {
            $stem = $fallbackStem;
        }

        $ext = Str::lower(pathinfo(trim($candidate), PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = $extension;
        }

        return $stem.'.'.$ext;
    }
}
