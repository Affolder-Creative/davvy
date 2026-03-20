<?php

namespace Tests\Unit;

use Tests\TestCase;

class LocalizationCatalogParityTest extends TestCase
{
    public function test_spanish_php_translation_catalogs_match_english_keys(): void
    {
        $baseDir = base_path('lang/en');
        $compareDir = base_path('lang/es');

        $baseFiles = glob($baseDir.'/*.php') ?: [];
        sort($baseFiles);

        foreach ($baseFiles as $baseFile) {
            $fileName = basename($baseFile);
            $compareFile = $compareDir.'/'.$fileName;

            $this->assertFileExists(
                $compareFile,
                "Missing translation file: lang/es/{$fileName}"
            );

            $baseKeys = $this->flattenTranslationKeys(require $baseFile);
            $compareKeys = $this->flattenTranslationKeys(require $compareFile);
            sort($baseKeys);
            sort($compareKeys);

            $missingInSpanish = array_values(array_diff($baseKeys, $compareKeys));
            $extraInSpanish = array_values(array_diff($compareKeys, $baseKeys));

            $this->assertSame(
                [],
                $missingInSpanish,
                "Missing keys in lang/es/{$fileName}: ".implode(', ', $missingInSpanish)
            );
            $this->assertSame(
                [],
                $extraInSpanish,
                "Extra keys in lang/es/{$fileName}: ".implode(', ', $extraInSpanish)
            );
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
