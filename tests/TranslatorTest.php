<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new Translator(dirname(__DIR__) . '/lang');
    }

    public function testFallbackIsEnglish(): void
    {
        $this->assertSame('en', Translator::FALLBACK);
    }

    public function testTranslatesKeyInActiveLanguage(): void
    {
        $this->translator->setLang('de');
        $this->assertSame('Anmelden', $this->translator->t('core', 'login_submit'));
    }

    public function testFallsBackToEnglishForMissingKey(): void
    {
        // The real lang files are fully translated, so the fallback is
        // verified against a fixture language directory that is missing
        // one German key on purpose.
        $dir = sys_get_temp_dir() . '/cb_lang_' . uniqid();
        mkdir($dir . '/en', 0775, true);
        mkdir($dir . '/de', 0775, true);
        file_put_contents($dir . '/en/core.php', "<?php return ['greeting' => 'Hello'];");
        file_put_contents($dir . '/de/core.php', "<?php return [];");
        $translator = new Translator($dir);
        $translator->setLang('de');
        $this->assertSame('Hello', $translator->t('core', 'greeting'));
        array_map('unlink', glob($dir . '/en/*') ?: []);
        array_map('unlink', glob($dir . '/de/*') ?: []);
        @rmdir($dir . '/en');
        @rmdir($dir . '/de');
        @rmdir($dir);
    }

    public function testRawKeyWhenUnknownEverywhere(): void
    {
        $this->translator->setLang('de');
        $this->assertSame('core.no_such_key', $this->translator->t('core', 'core.no_such_key'));
    }

    public function testPlaceholderReplacement(): void
    {
        $this->translator->setLang('en');
        $this->assertSame(
            'Edit: Home',
            $this->translator->t('pages', 'edit_page', ['title' => 'Home'])
        );
    }

    public function testUnknownLanguageKeepsFallback(): void
    {
        $this->translator->setLang('zz');
        $this->assertSame(Translator::FALLBACK, $this->translator->lang());
    }

    public function testEnglishAndGermanHaveIdenticalKeys(): void
    {
        foreach (['core', 'writing', 'pages'] as $domain) {
            $en = $this->translator->domain('en', $domain);
            $de = $this->translator->domain('de', $domain);
            $this->assertNotNull($en);
            $this->assertNotNull($de);
            $this->assertSame(
                [],
                array_values(array_diff(array_keys($en), array_keys($de))),
                "de/{$domain}.php is missing keys present in en/{$domain}.php"
            );
            $this->assertSame(
                [],
                array_values(array_diff(array_keys($de), array_keys($en))),
                "de/{$domain}.php has extra keys not present in en/{$domain}.php"
            );
        }
    }

    public function testNoEmptyTranslations(): void
    {
        foreach (['en', 'de'] as $lang) {
            foreach (['core', 'writing', 'pages'] as $domain) {
                $map = $this->translator->domain($lang, $domain);
                $this->assertNotNull($map);
                foreach ($map as $key => $value) {
                    $this->assertNotSame('', $value, "{$lang}/{$domain}.php: empty value for {$key}");
                }
            }
        }
    }

    public function testAvailableLanguagesIncludeFallback(): void
    {
        $this->assertContains('en', $this->translator->availableLanguages());
        $this->assertContains('de', $this->translator->availableLanguages());
    }
}
