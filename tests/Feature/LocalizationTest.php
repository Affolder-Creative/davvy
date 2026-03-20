<?php

namespace Tests\Feature;

use App\Mail\AdminUserInviteMail;
use App\Mail\PublicRegistrationVerificationMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.locale', 'en');
        config()->set('app.fallback_locale', 'en');
        config()->set('app.supported_locales', ['de', 'en', 'es', 'fr', 'it', 'pt']);
    }

    public function test_public_locale_negotiation_honors_query_then_header_then_accept_language_then_fallback(): void
    {
        $queryResponse = $this->json('GET', '/api/public/config?locale=es', [], [
            'X-Davvy-Locale' => 'en',
            'Accept-Language' => 'en-US,en;q=0.9',
        ]);

        $queryResponse->assertOk();
        $queryResponse->assertJsonPath('locale', 'es');

        $headerResponse = $this->json('GET', '/api/public/config', [], [
            'X-Davvy-Locale' => 'es',
            'Accept-Language' => 'en-US,en;q=0.9',
        ]);

        $headerResponse->assertOk();
        $headerResponse->assertJsonPath('locale', 'es');

        $acceptLanguageResponse = $this->json('GET', '/api/public/config', [], [
            'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
        ]);

        $acceptLanguageResponse->assertOk();
        $acceptLanguageResponse->assertJsonPath('locale', 'fr');

        $fallbackResponse = $this->json('GET', '/api/public/config', [], [
            'Accept-Language' => 'nl-NL,nl;q=0.9',
        ]);

        $fallbackResponse->assertOk();
        $fallbackResponse->assertJsonPath('locale', 'en');
        $fallbackResponse->assertJsonPath('fallback_locale', 'en');
        $fallbackResponse->assertJsonPath('supported_locales', ['de', 'en', 'es', 'fr', 'it', 'pt']);
    }

    public function test_authenticated_user_locale_takes_precedence_over_request_locale_inputs(): void
    {
        $user = User::factory()->create([
            'locale' => 'es',
        ]);

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Davvy-Locale' => 'en',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->getJson('/api/auth/me?locale=en');

        $response->assertOk();
        $response->assertJsonPath('locale', 'es');
        $response->assertJsonPath('fallback_locale', 'en');
        $response->assertJsonPath('supported_locales', ['de', 'en', 'es', 'fr', 'it', 'pt']);
    }

    public function test_login_response_includes_locale_payload(): void
    {
        User::factory()->create([
            'email' => 'locale-login@example.test',
            'password' => 'password1234',
            'locale' => 'es',
        ]);

        $response = $this->withHeaders([
            'X-Davvy-Locale' => 'fr',
        ])->postJson('/api/auth/login', [
            'email' => 'locale-login@example.test',
            'password' => 'password1234',
        ]);

        $response->assertOk();
        $response->assertJsonPath('locale', 'fr');
        $response->assertJsonPath('fallback_locale', 'en');
        $response->assertJsonPath('supported_locales', ['de', 'en', 'es', 'fr', 'it', 'pt']);
        $response->assertJsonStructure([
            'user',
            'locale',
            'supported_locales',
            'fallback_locale',
        ]);
    }

    public function test_authenticated_user_can_update_locale_and_receive_updated_payload(): void
    {
        $user = User::factory()->create([
            'locale' => 'en',
        ]);

        $response = $this->actingAs($user)
            ->patchJson('/api/auth/locale', [
                'locale' => 'de',
            ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('locale', 'de');
        $response->assertJsonPath('user.locale', 'de');
        $response->assertJsonPath('supported_locales', ['de', 'en', 'es', 'fr', 'it', 'pt']);
        $response->assertJsonPath('fallback_locale', 'en');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'de',
        ]);
    }

    public function test_locale_update_rejects_unsupported_locale(): void
    {
        $user = User::factory()->create([
            'locale' => 'en',
        ]);

        $response = $this->actingAs($user)
            ->patchJson('/api/auth/locale', [
                'locale' => 'nl',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['locale']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'en',
        ]);
    }

    public function test_supported_locales_are_normalized_from_config(): void
    {
        config()->set('app.supported_locales', [' EN ', 'es', '', 'de', 'EN', 'fr ', ' it ', ' pt ']);

        $response = $this->getJson('/api/public/config');

        $response->assertOk();
        $response->assertJsonPath('supported_locales', ['en', 'es', 'de', 'fr', 'it', 'pt']);
        $response->assertJsonPath('fallback_locale', 'en');
        $response->assertJsonPath('locale', 'en');
    }

    public function test_fallback_locale_is_used_when_candidate_is_unsupported(): void
    {
        config()->set('app.fallback_locale', 'fr');
        config()->set('app.supported_locales', ['de', 'en', 'fr']);

        $response = $this->json('GET', '/api/public/config?locale=nl', [], [
            'X-Davvy-Locale' => 'nl-NL',
            'Accept-Language' => 'es-MX,es;q=0.9',
        ]);

        $response->assertOk();
        $response->assertJsonPath('locale', 'fr');
        $response->assertJsonPath('fallback_locale', 'fr');
        $response->assertJsonPath('supported_locales', ['de', 'en', 'fr']);
    }

    public function test_login_validation_error_message_localizes_to_request_locale(): void
    {
        $response = $this->withHeaders([
            'X-Davvy-Locale' => 'es',
        ])->postJson('/api/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'incorrect-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            trans('auth.credentials_invalid', locale: 'es'),
        );
    }

    public function test_login_validation_error_message_localizes_to_german_french_italian_and_portuguese(): void
    {
        foreach (['de', 'fr', 'it', 'pt'] as $locale) {
            $response = $this->withHeaders([
                'X-Davvy-Locale' => $locale,
            ])->postJson('/api/auth/login', [
                'email' => 'missing@example.com',
                'password' => 'incorrect-password',
            ]);

            $response->assertStatus(422);
            $response->assertJsonPath(
                'message',
                trans('auth.credentials_invalid', locale: $locale),
            );
        }
    }

    public function test_admin_backup_validation_error_localizes_to_authenticated_user_locale(): void
    {
        $admin = User::factory()->admin()->create([
            'locale' => 'en',
        ]);

        $response = $this->actingAs($admin)
            ->withHeaders([
                'X-Davvy-Locale' => 'es',
            ])
            ->patchJson('/api/admin/settings/backups', [
                ...$this->validBackupSettingsPayload(),
                'enabled' => true,
                'local_enabled' => false,
                's3_enabled' => false,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            trans('backups.enable_at_least_one_destination', locale: 'en'),
        );
    }

    public function test_onboarding_mails_render_spanish_subject_and_body(): void
    {
        $user = User::factory()->create([
            'name' => 'Invitado',
            'locale' => 'es',
        ]);

        $expiresAt = CarbonImmutable::parse('2026-03-21 10:30:00', 'UTC');
        $verifyMail = (new PublicRegistrationVerificationMail(
            user: $user,
            verificationUrl: 'https://example.test/verify-email?token=abc',
            expiresAt: $expiresAt,
        ))->locale('es');

        $inviteMail = (new AdminUserInviteMail(
            user: $user,
            inviteUrl: 'https://example.test/invite?token=abc',
            expiresAt: $expiresAt,
        ))->locale('es');

        $verifySubject = $this->withAppLocale('es', fn (): string => $verifyMail->envelope()->subject);
        $inviteSubject = $this->withAppLocale('es', fn (): string => $inviteMail->envelope()->subject);

        $this->assertSame(
            trans('emails.verify_email_subject', ['app' => config('app.name', 'Davvy')], 'es'),
            $verifySubject,
        );
        $this->assertSame(
            trans('emails.admin_invite_subject', ['app' => config('app.name', 'Davvy')], 'es'),
            $inviteSubject,
        );

        $verifyHtml = $this->withAppLocale('es', fn (): string => $verifyMail->render());
        $inviteHtml = $this->withAppLocale('es', fn (): string => $inviteMail->render());

        $this->assertStringContainsString('Verifica tu correo electrónico', $verifyHtml);
        $this->assertStringContainsString('Estás invitado a', $inviteHtml);
        $this->assertStringContainsString('lang="es"', $verifyHtml);
        $this->assertStringContainsString('lang="es"', $inviteHtml);
    }

    public function test_onboarding_mails_render_german_french_italian_and_portuguese_subject_and_body(): void
    {
        $cases = [
            'de' => [
                'name' => 'Eingeladen',
                'verify_heading' => 'Bestätige deine E-Mail-Adresse',
                'invite_heading' => 'Du wurdest zu',
            ],
            'fr' => [
                'name' => 'Invité',
                'verify_heading' => 'Vérifiez votre adresse e-mail',
                'invite_heading' => 'Vous êtes invité à rejoindre',
            ],
            'it' => [
                'name' => 'Invitato',
                'verify_heading' => 'Verifica la tua email',
                'invite_heading' => 'Sei invitato a',
            ],
            'pt' => [
                'name' => 'Convidado',
                'verify_heading' => 'Verifique seu e-mail',
                'invite_heading' => 'Você está convidado para',
            ],
        ];

        foreach ($cases as $locale => $case) {
            $user = User::factory()->create([
                'name' => $case['name'],
                'locale' => $locale,
            ]);

            $expiresAt = CarbonImmutable::parse('2026-03-21 10:30:00', 'UTC');
            $verifyMail = (new PublicRegistrationVerificationMail(
                user: $user,
                verificationUrl: 'https://example.test/verify-email?token=abc',
                expiresAt: $expiresAt,
            ))->locale($locale);

            $inviteMail = (new AdminUserInviteMail(
                user: $user,
                inviteUrl: 'https://example.test/invite?token=abc',
                expiresAt: $expiresAt,
            ))->locale($locale);

            $verifySubject = $this->withAppLocale($locale, fn (): string => $verifyMail->envelope()->subject);
            $inviteSubject = $this->withAppLocale($locale, fn (): string => $inviteMail->envelope()->subject);

            $this->assertSame(
                trans('emails.verify_email_subject', ['app' => config('app.name', 'Davvy')], $locale),
                $verifySubject,
            );
            $this->assertSame(
                trans('emails.admin_invite_subject', ['app' => config('app.name', 'Davvy')], $locale),
                $inviteSubject,
            );

            $verifyHtml = $this->withAppLocale($locale, fn (): string => $verifyMail->render());
            $inviteHtml = $this->withAppLocale($locale, fn (): string => $inviteMail->render());

            $this->assertStringContainsString($case['verify_heading'], $verifyHtml);
            $this->assertStringContainsString($case['invite_heading'], $inviteHtml);
            $this->assertStringContainsString('lang="'.$locale.'"', $verifyHtml);
            $this->assertStringContainsString('lang="'.$locale.'"', $inviteHtml);
        }
    }

    public function test_german_french_italian_and_portuguese_catalogs_match_english_translation_keys(): void
    {
        $englishFiles = glob((string) lang_path('en/*.php'));

        $this->assertIsArray($englishFiles);
        $this->assertNotEmpty($englishFiles);

        foreach (['de', 'fr', 'it', 'pt'] as $locale) {
            foreach ($englishFiles as $englishPath) {
                $filename = basename($englishPath);
                $localePath = (string) lang_path($locale.'/'.$filename);

                $this->assertFileExists(
                    $localePath,
                    sprintf('Missing %s translation file: %s', strtoupper($locale), $filename),
                );

                $englishKeys = $this->flattenTranslationKeys(require $englishPath);
                $localeKeys = $this->flattenTranslationKeys(require $localePath);

                $this->assertSame(
                    $englishKeys,
                    $localeKeys,
                    sprintf('Key mismatch for %s/%s', $locale, $filename),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validBackupSettingsPayload(): array
    {
        return [
            'enabled' => false,
            'local_enabled' => true,
            'local_path' => '/tmp/davvy-backups',
            's3_enabled' => false,
            's3_disk' => 's3',
            's3_prefix' => 'davvy-backups',
            'schedule_times' => ['02:30'],
            'timezone' => 'UTC',
            'weekly_day' => 0,
            'monthly_day' => 1,
            'yearly_month' => 1,
            'yearly_day' => 1,
            'retention_daily' => 7,
            'retention_weekly' => 4,
            'retention_monthly' => 12,
            'retention_yearly' => 3,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withAppLocale(string $locale, callable $callback): mixed
    {
        $originalLocale = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<int, string>
     */
    private function flattenTranslationKeys(array $translations): array
    {
        $keys = array_keys(Arr::dot($translations));
        sort($keys);

        return $keys;
    }
}
