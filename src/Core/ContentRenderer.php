<?php

declare(strict_types=1);

namespace Core;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdown\GithubFlavoredMarkdownExtension;
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

    private function rewriteInternalLinks(string $html): string
    {
        return (string)preg_replace_callback(
            '/\[\[([a-z0-9_-]+):([a-z0-9_-]+)\]\]/i',
            function (array $m): string {
                $resolved = $this->registry->resolveLink($this->db, $m[1], $m[2]);
                if ($resolved === null) {
                    return '<a class="broken-link" href="#">[[' . e($m[1] . ':' . $m[2]) . ']]</a>';
                }
                return '<a href="' . e($resolved['url']) . '">' . e($m[1] . ':' . $m[2]) . '</a>';
            },
            $html
        );
    }
}
