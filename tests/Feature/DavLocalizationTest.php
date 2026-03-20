<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dav\Backends\LaravelAuthBackend;
use App\Services\Dav\IcsValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sabre\DAV\Exception\BadRequest;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Tests\TestCase;

class DavLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dav_auth_sets_application_locale_from_user_preference(): void
    {
        $user = User::factory()->create([
            'email' => 'dav-locale@example.test',
            'password' => 'password1234',
            'locale' => 'es',
        ]);

        $authBackend = app(LaravelAuthBackend::class);

        $request = new Request('PROPFIND', '/dav/', [
            'Authorization' => 'Basic '.base64_encode($user->email.':password1234'),
        ]);
        $response = new Response;

        [$ok, $principal] = $authBackend->check($request, $response);

        $this->assertTrue($ok);
        $this->assertSame('principals/'.$user->id, $principal);
        $this->assertSame('es', app()->getLocale());
    }

    public function test_authenticated_dav_validation_errors_use_authenticated_user_locale(): void
    {
        $user = User::factory()->create([
            'email' => 'dav-locale-errors@example.test',
            'password' => 'password1234',
            'locale' => 'es',
        ]);

        $authBackend = app(LaravelAuthBackend::class);
        $request = new Request('PROPFIND', '/dav/', [
            'Authorization' => 'Basic '.base64_encode($user->email.':password1234'),
        ]);
        $response = new Response;

        [$ok] = $authBackend->check($request, $response);
        $this->assertTrue($ok);

        $validator = app(IcsValidator::class);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Se esperaba un payload VCALENDAR.');

        $validator->validateAndNormalize("BEGIN:VCARD\nVERSION:4.0\nFN:Wrong Payload\nEND:VCARD");
    }
}
