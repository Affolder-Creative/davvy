<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaShellTest extends TestCase
{
    public function test_app_shell_includes_ios_pwa_launch_metadata_and_boot_screen(): void
    {
        $this->withoutVite();

        $response = $this->get('/login');

        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertSame(64, substr_count($html, 'rel="apple-touch-startup-image"'));

        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('manifest.webmanifest', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable" content="yes"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-status-bar-style" content="black-translucent"', $html);

        $this->assertStringContainsString('images/splash/ios-splash-ns-light-1170x2532.png', $html);
        $this->assertStringContainsString('images/splash/ios-splash-ns-dark-2532x1170.png', $html);
        $this->assertStringContainsString('(prefers-color-scheme: dark) and (device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)', $html);
        $this->assertStringContainsString('images/splash/ios-splash-ns-light-2048x2732.png', $html);
        $this->assertStringContainsString('(device-width: 1024px) and (device-height: 1366px)', $html);

        $this->assertFileExists(public_path('images/splash/ios-splash-ns-light-1170x2532.png'));
        $this->assertFileExists(public_path('images/splash/ios-splash-ns-dark-2532x1170.png'));
    }
}
