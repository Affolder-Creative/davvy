<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DavAuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_401_dav_requests_do_not_clear_failed_auth_throttle_state(): void
    {
        config()->set('dav.auth_throttle.max_attempts', 2);
        config()->set('dav.auth_throttle.decay_seconds', 120);

        $user = User::factory()->create([
            'email' => 'dav-throttle@example.test',
            'password' => 'password1234',
        ]);

        $badAuthorization = 'Basic '.base64_encode($user->email.':wrong-password');

        $firstFailure = $this->davPropfind('/dav/', $badAuthorization);
        $this->assertSame(401, $firstFailure->getStatusCode());

        $nonAuthResponse = $this->call(
            method: 'GET',
            uri: '/dav/does-not-exist',
            server: [
                'HTTP_AUTHORIZATION' => $badAuthorization,
            ],
        );
        $this->assertNotSame(401, $nonAuthResponse->getStatusCode());
        $this->assertNotSame(429, $nonAuthResponse->getStatusCode());

        $secondFailure = $this->davPropfind('/dav/', $badAuthorization);
        $this->assertSame(401, $secondFailure->getStatusCode());

        $blocked = $this->davPropfind('/dav/', $badAuthorization);
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertStringContainsString(
            'Too many DAV authentication attempts',
            (string) $blocked->getContent(),
        );
    }

    public function test_throttle_message_uses_request_locale_for_unauthenticated_dav_requests(): void
    {
        config()->set('dav.auth_throttle.max_attempts', 1);
        config()->set('dav.auth_throttle.decay_seconds', 120);

        $user = User::factory()->create([
            'email' => 'dav-throttle-locale@example.test',
            'password' => 'password1234',
        ]);

        $badAuthorization = 'Basic '.base64_encode($user->email.':wrong-password');

        $this->davPropfind('/dav/', $badAuthorization, [
            'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9,en;q=0.8',
        ])->assertStatus(401);

        $blocked = $this->davPropfind('/dav/', $badAuthorization, [
            'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9,en;q=0.8',
        ]);

        $blocked->assertStatus(429);
        $this->assertStringContainsString(
            'Demasiados intentos de autenticación DAV',
            (string) $blocked->getContent(),
        );
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function davPropfind(string $uri, string $authorization, array $server = []): TestResponse
    {
        return $this->call(
            method: 'PROPFIND',
            uri: $uri,
            server: [
                'HTTP_AUTHORIZATION' => $authorization,
                'HTTP_DEPTH' => '0',
                'CONTENT_TYPE' => 'application/xml; charset=utf-8',
                ...$server,
            ],
            content: '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>',
        );
    }
}
