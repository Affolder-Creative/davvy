<?php

namespace App\Notifications\Messages;

use NotificationChannels\WebPush\WebPushMessageInterface;

class DavvyWebPushMessage implements WebPushMessageInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $url,
        private readonly string $tag,
        private readonly int $badgeCount,
        private readonly array $data,
        private readonly string $icon = '/images/icons/icon-192.png',
        private readonly string $badge = '/images/icons/icon-192.png',
        private readonly array $options = [
            'TTL' => 3600,
            'contentType' => 'application/json',
        ],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $badgeCount = max(0, $this->badgeCount);
        $targetPath = $this->sameOriginPath($this->url);

        return [
            'web_push' => 8030,
            'notification' => [
                'title' => $this->title,
                'body' => $this->body,
                'icon' => url($this->icon),
                'badge' => url($this->badge),
                'tag' => $this->tag,
                'navigate' => url($targetPath),
                'app_badge' => $badgeCount,
                'data' => array_merge($this->data, [
                    'url' => $targetPath,
                    'badge_count' => $badgeCount,
                ]),
            ],
            'mutable' => true,
        ];
    }

    private function sameOriginPath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        if (str_starts_with($url, '/')) {
            return $this->pathWithQueryAndFragment($url);
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '/';
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            return $this->isSameOrigin($parts)
                ? $this->pathWithQueryAndFragment($url)
                : '/';
        }

        return $this->pathWithQueryAndFragment('/'.ltrim($url, '/'));
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function isSameOrigin(array $parts): bool
    {
        $appParts = parse_url((string) config('app.url', ''));
        if ($appParts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $appScheme = strtolower((string) ($appParts['scheme'] ?? ''));
        $appHost = strtolower((string) ($appParts['host'] ?? ''));

        return $scheme !== ''
            && $host !== ''
            && $scheme === $appScheme
            && $host === $appHost
            && $this->originPort($parts, $scheme) === $this->originPort($appParts, $appScheme);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function originPort(array $parts, string $scheme): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
    }

    private function pathWithQueryAndFragment(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '/';
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/';
        }

        $query = isset($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== ''
            ? '#'.$parts['fragment']
            : '';

        return $path.$query.$fragment;
    }
}
