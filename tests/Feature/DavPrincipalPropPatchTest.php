<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DavPrincipalPropPatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_principal_proppatch_rejects_invalid_email_value(): void
    {
        $user = User::factory()->create([
            'email' => 'principal-invalid@example.test',
            'password' => 'password1234',
        ]);

        $response = $this->principalPropPatch(
            user: $user,
            propertyXml: '<s:email-address>not-an-email</s:email-address>',
        );

        $response->assertStatus(207);
        $this->assertStringContainsString('HTTP/1.1 422', (string) $response->getContent());
        $this->assertSame('principal-invalid@example.test', (string) $user->fresh()?->email);
    }

    public function test_principal_proppatch_returns_conflict_for_duplicate_email_instead_of_server_error(): void
    {
        $owner = User::factory()->create([
            'email' => 'principal-owner@example.test',
            'password' => 'password1234',
        ]);
        $existing = User::factory()->create([
            'email' => 'principal-existing@example.test',
        ]);

        $response = $this->principalPropPatch(
            user: $owner,
            propertyXml: '<s:email-address>'.$existing->email.'</s:email-address>',
        );

        $response->assertStatus(207);
        $this->assertStringContainsString('HTTP/1.1 409', (string) $response->getContent());
        $this->assertStringNotContainsString('HTTP/1.1 500', (string) $response->getContent());
        $this->assertSame('principal-owner@example.test', (string) $owner->fresh()?->email);
    }

    public function test_principal_proppatch_clears_email_verification_when_email_changes(): void
    {
        $user = User::factory()->create([
            'email' => 'principal-verified@example.test',
            'password' => 'password1234',
            'email_verified_at' => now()->subHour(),
        ]);

        $response = $this->principalPropPatch(
            user: $user,
            propertyXml: '<s:email-address>PRINCIPAL-UPDATED@EXAMPLE.TEST</s:email-address>',
        );

        $response->assertStatus(207);
        $this->assertStringContainsString('HTTP/1.1 200', (string) $response->getContent());

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('principal-updated@example.test', (string) $fresh->email);
        $this->assertNull($fresh->email_verified_at);
    }

    private function principalPropPatch(User $user, string $propertyXml): TestResponse
    {
        $payload = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<d:propertyupdate xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">
  <d:set>
    <d:prop>
      {$propertyXml}
    </d:prop>
  </d:set>
</d:propertyupdate>
XML;

        return $this->call(
            method: 'PROPPATCH',
            uri: '/dav/principals/'.$user->id.'/',
            server: [
                'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($user->email.':password1234'),
                'HTTP_DEPTH' => '0',
                'CONTENT_TYPE' => 'application/xml; charset=utf-8',
            ],
            content: $payload,
        );
    }
}
