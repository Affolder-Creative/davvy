<?php

namespace Tests\Unit;

use Tests\TestCase;

class LocalizationCatalogParityTest extends TestCase
{
    public function test_php_translation_catalogs_match_english_keys_for_all_supported_locale_directories(): void
    {
        $baseDir = base_path('lang/en');
        $localeDirectories = glob(base_path('lang/*'), GLOB_ONLYDIR) ?: [];
        sort($localeDirectories);

        $baseFiles = glob($baseDir.'/*.php') ?: [];
        sort($baseFiles);

        foreach ($localeDirectories as $localeDirectory) {
            $locale = basename($localeDirectory);
            if ($locale === 'en') {
                continue;
            }

            foreach ($baseFiles as $baseFile) {
                $fileName = basename($baseFile);
                $compareFile = $localeDirectory.'/'.$fileName;

                $this->assertFileExists(
                    $compareFile,
                    "Missing translation file: lang/{$locale}/{$fileName}"
                );

                $baseKeys = $this->flattenTranslationKeys(require $baseFile);
                $compareKeys = $this->flattenTranslationKeys(require $compareFile);
                sort($baseKeys);
                sort($compareKeys);

                $missingKeys = array_values(array_diff($baseKeys, $compareKeys));
                $extraKeys = array_values(array_diff($compareKeys, $baseKeys));

                $this->assertSame(
                    [],
                    $missingKeys,
                    "Missing keys in lang/{$locale}/{$fileName}: ".implode(', ', $missingKeys)
                );
                $this->assertSame(
                    [],
                    $extraKeys,
                    "Extra keys in lang/{$locale}/{$fileName}: ".implode(', ', $extraKeys)
                );
            }
        }
    }

    /**
     * @param  array<mixed>  $translations
     * @return array<int, string>
     */
    private function flattenTranslationKeys(array $translations, string $prefix = ''): array
    {
        $keys = [];

        foreach ($translations as $key => $value) {
            $next = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenTranslationKeys($value, $next));

                continue;
            }

            $keys[] = $next;
        }

        return $keys;
    }
}
