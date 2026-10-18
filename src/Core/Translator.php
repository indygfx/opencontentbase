<?php

declare(strict_types=1);

namespace Core;

/**
 * Minimal array-based translator. Language files live in lang/<code>/<domain>.php
 * and return a flat key => string map. Lookup falls back to the English default
 * and finally to the raw key, so missing translations never break the UI.
 */
final class Translator
{
    public const FALLBACK = 'en';

    private static ?Translator $instance = null;

    /** @var array<string, array<string, string>> loaded maps: "<lang>.<domain>" => keys */
    private array $loaded = [];

    private string $lang = self::FALLBACK;

    public function __construct(private readonly string $langDir)
    {
    }

    public static function init(string $langDir): void
    {
        self::$instance = new self($langDir);
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function setLang(string $lang): void
    {
        if (is_dir($this->langDir . '/' . preg_replace('/[^a-z0-9_-]/', '', $lang))) {
            $this->lang = $lang;
        }
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /** @return list<string> available language codes, always including the fallback */
    public function availableLanguages(): array
    {
        $codes = [self::FALLBACK];
        foreach (glob($this->langDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $code = basename($dir);
            if ($code !== self::FALLBACK && is_file($this->langDir . '/' . $code . '/core.php')) {
                $codes[] = $code;
            }
        }
        sort($codes);
        return $codes;
    }

    /**
     * @param array<string, string> $params {name} placeholders
     */
    public function t(string $domain, string $key, array $params = []): string
    {
        $text = $this->lookup($this->lang, $domain, $key)
            ?? $this->lookup(self::FALLBACK, $domain, $key)
            ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
        return $text;
    }

    /** @return array<string, string>|null */
    public function domain(string $lang, string $domain): ?array
    {
        $cacheKey = $lang . '.' . $domain;
        if (!isset($this->loaded[$cacheKey])) {
            $file = $this->langDir . '/' . $lang . '/' . $domain . '.php';
            $map = is_file($file) ? (array)(include $file) : null;
            if (!is_array($map)) {
                return null;
            }
            $this->loaded[$cacheKey] = array_filter($map, 'is_string');
        }
        return $this->loaded[$cacheKey];
    }

    /** @return list<string> domains of the fallback language (parity check base) */
    public function fallbackDomains(): array
    {
        $domains = [];
        foreach (glob($this->langDir . '/' . self::FALLBACK . '/*.php') ?: [] as $file) {
            $domains[] = basename($file, '.php');
        }
        sort($domains);
        return $domains;
    }

    private function lookup(string $lang, string $domain, string $key): ?string
    {
        $map = $this->domain($lang, $domain);
        return $map === null ? null : ($map[$key] ?? null);
    }
}
