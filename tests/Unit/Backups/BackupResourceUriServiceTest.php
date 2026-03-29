<?php

namespace Tests\Unit\Backups;

use App\Services\Backups\BackupResourceUriService;
use Tests\TestCase;

class BackupResourceUriServiceTest extends TestCase
{
    public function test_normalize_resource_uri_applies_slug_and_fallbacks(): void
    {
        $service = app(BackupResourceUriService::class);

        $this->assertSame('my-item.vcf', $service->normalizeResourceUri('My Item.VCF', 'ics', 'item'));
        $this->assertSame('item.ics', $service->normalizeResourceUri('***', 'ics', 'item'));
        $this->assertSame('my-item.ics', $service->normalizeResourceUri('My Item', 'ics', 'item'));
    }

    public function test_next_unique_resource_uri_avoids_collisions_and_updates_pool(): void
    {
        $service = app(BackupResourceUriService::class);
        $pool = ['card.vcf', 'card-2.vcf'];

        $next = $service->nextUniqueResourceUri('Card.vcf', 'vcf', 'card', $pool);

        $this->assertSame('card-3.vcf', $next);
        $this->assertSame(['card.vcf', 'card-2.vcf', 'card-3.vcf'], $pool);
    }
}
