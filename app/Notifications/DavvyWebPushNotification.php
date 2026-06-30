<?php

namespace App\Notifications;

use App\Notifications\Messages\DavvyWebPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessageInterface;

class DavvyWebPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $type,
        private readonly string $title,
        private readonly string $body,
        private readonly string $url,
        private readonly string $tag,
        private readonly int $badgeCount,
    ) {}

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessageInterface
    {
        return new DavvyWebPushMessage(
            title: $this->title,
            body: $this->body,
            url: $this->url,
            tag: $this->tag,
            badgeCount: $this->badgeCount,
            data: [
                'type' => $this->type,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
            'badge_count' => $this->badgeCount,
        ];
    }
}
