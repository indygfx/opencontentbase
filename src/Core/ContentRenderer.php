<?php

declare(strict_types=1);

namespace Core;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

final class ContentRenderer
{
    private MarkdownConverter $converter;
    private ModuleRegistry $registry;
    private Database $db;

    public function __construct(Database $db, ModuleRegistry $registry)
    {
        $this->db = $db;
        $this->registry = $registry;

        $config = [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ];
        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new DisallowedRawHtmlExtension());
        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): string
    {
        $html = (string)$this->converter->convert($markdown);
        return $this->rewriteInternalLinks($html);
    }

    /**
     * Zwei-Phasen-Pipeline: Phase 1 sammelt alle [[type:slug]]-Referenzen im
     * HTML; phase 2 resolves them in one batch via the registry (batch instead of
     * N+1), erst danach wird ersetzt.
     */
    private function rewriteInternalLinks(string $html): string
    {
        $pattern = '/\[\[([a-z0-9_-]+):([a-z0-9_-]+)\]\]/i';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === 0) {
            return $html;
        }

        $references = [];
        foreach ($matches as $m) {
            $references[strtolower($m[1])][] = strtolower($m[2]);
        }

        $resolved = $this->registry->resolveLinks($this->db, $references);

        return (string)preg_replace_callback(
            $pattern,
            function (array $m) use ($resolved): string {
                $type = strtolower($m[1]);
                $slug = strtolower($m[2]);
                $link = $resolved[$type][$slug] ?? null;
                if ($link === null) {
                    return '<a class="broken-link" href="#">[[' . e($m[1] . ':' . $m[2]) . ']]</a>';
                }
                return '<a href="' . e($link['url']) . '">' . e($m[1] . ':' . $m[2]) . '</a>';
            },
            $html
        );
    }
}
