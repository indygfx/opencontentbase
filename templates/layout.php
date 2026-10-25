<!DOCTYPE html>
<html lang="<?= e(Core\Translator::instance()?->lang() ?? Core\Translator::FALLBACK) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'ContentBase') ?></title>
    <style>
        :root { --ink: #1f2430; --muted: #6b7280; --line: #e5e7eb; --accent: #2563eb; }
        body { font-family: system-ui, sans-serif; color: var(--ink); margin: 0; background: #f8fafc; }
        .topbar { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1.25rem; background: #fff; border-bottom: 1px solid var(--line); }
        .brand { font-weight: 700; text-decoration: none; color: var(--ink); }
        .topbar nav { display: flex; gap: 1rem; align-items: center; }
        .topbar nav a { color: var(--accent); text-decoration: none; }
        main { max-width: 52rem; margin: 1.5rem auto; padding: 0 1rem; }
        h1, h2 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--line); }
        label { display: block; margin-bottom: 1rem; font-weight: 600; }
        input, textarea { width: 100%; padding: 0.5rem; border: 1px solid var(--line); border-radius: 4px; font: inherit; }
        textarea { font-family: ui-monospace, monospace; }
        button, .button { background: var(--accent); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font: inherit; }
        form.inline { display: inline; margin: 0; }
        .error { color: #b91c1c; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 6px; padding: 1.5rem; }
        .narrow { max-width: 24rem; margin: 3rem auto; }
        .content { background: #fff; border: 1px solid var(--line); border-radius: 6px; padding: 1.5rem; }
        .content table { border: 1px solid var(--line); }
        .broken-link { color: #b91c1c; background: #fef2f2; border-bottom: 1px dashed #b91c1c; }        .outline-box { background: #f8fafc; border: 1px solid var(--line); border-left: 4px solid var(--accent); border-radius: 6px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; }        .outline-box h2 { margin: 0 0 0.5rem 0; font-size: 1rem; }
        .muted { color: var(--muted); }
        .cover-preview { max-width: 22rem; max-height: 22rem; border: 1px solid var(--line); border-radius: 6px; display: block; }
        .character-portrait { max-width: 10rem; max-height: 10rem; border: 1px solid var(--line); border-radius: 50%; display: block; margin-bottom: 1rem; }
        .graph-wrap { background: #fff; border: 1px solid var(--line); border-radius: 6px; padding: 0.5rem; margin-bottom: 1rem; }
        .relationship-graph { width: 100%; height: auto; display: block; }
        .graph-node-circle { fill: #cbd5e1; stroke: #64748b; stroke-width: 1.5; }
        .graph-node-label-bg { fill: #fff; opacity: 0.85; }
        .graph-node-label { font-size: 12px; fill: var(--ink); }
        .graph-edge { stroke-width: 2; }
        .rel-love { stroke: #db2777; }
        .rel-bond { stroke: #16a34a; }
        .rel-esteem { stroke: #2563eb; }
        .rel-hostility { stroke: #b91c1c; }
        .rel-rivalry { stroke: #f59e0b; }
        .rel-fear { stroke: #7c3aed; }
        .rel-neutral { stroke: #9ca3af; }
        .graph-edge-label { font-size: 11px; }
        .graph-edge-label.rel-love { fill: #db2777; }
        .graph-edge-label.rel-bond { fill: #16a34a; }
        .graph-edge-label.rel-esteem { fill: #2563eb; }
        .graph-edge-label.rel-hostility { fill: #b91c1c; }
        .graph-edge-label.rel-rivalry { fill: #f59e0b; }
        .graph-edge-label.rel-fear { fill: #7c3aed; }
        .graph-edge-label.rel-neutral { fill: #6b7280; }
        .graph-legend-swatch { border: 1px solid var(--line); }
        .graph-legend-swatch.rel-love { background: #db2777; }
        .graph-legend-swatch.rel-bond { background: #16a34a; }
        .graph-legend-swatch.rel-esteem { background: #2563eb; }
        .graph-legend-swatch.rel-hostility { background: #b91c1c; }
        .graph-legend-swatch.rel-rivalry { background: #f59e0b; }
        .graph-legend-swatch.rel-fear { background: #7c3aed; }
        .graph-legend-swatch.rel-neutral { background: #9ca3af; }
        .graph-legend { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 0.75rem; }
        .graph-legend li { display: flex; align-items: center; gap: 0.35rem; }
        .graph-legend-swatch { width: 1rem; height: 1rem; border-radius: 3px; display: inline-block; }
        .progress { display: flex; align-items: center; gap: 0.6rem; margin: 0.25rem 0 0.75rem 0; }
        .progress-track { flex: 1 1 auto; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; }
        .progress-bar { height: 100%; background: #f59e0b; border-radius: 3px; }
        .progress-bar.is-done { background: #16a34a; }
        .progress-bar.is-partial { background: #f59e0b; }
        .progress-label { flex: 0 0 auto; font-size: 0.75rem; color: var(--muted); text-align: right; }
        .progress--overall { margin-bottom: 1.25rem; }
        /* Revision history and diffs */
        .badge { display: inline-block; font-size: 0.7rem; font-weight: 600; padding: 0.05rem 0.4rem;
                 border-radius: 999px; background: #e0f2fe; color: #0369a1; margin-left: 0.35rem; }
        .notice { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 0.5rem 0.75rem; }
        .diff { border: 1px solid var(--line); border-radius: 6px; overflow-x: auto; }
        .diff-body { margin: 0; padding: 0.5rem 0; background: var(--card, #fff); font-size: 0.85rem; line-height: 1.45; }
        .diff-line { display: block; white-space: pre-wrap; word-break: break-word; padding: 0 0.75rem; }
        .diff-line.diff-same { color: var(--muted); }
        .diff-line.diff-added { background: #dcfce7; }
        .diff-line.diff-removed { background: #fee2e2; }
        .diff-collapse { border-top: 1px dashed var(--line); }
        .diff-collapse summary { cursor: pointer; padding: 0.25rem 0.75rem; color: var(--muted); font-size: 0.8rem; }
        .diff-title-change { background: #fef9c3; border: 1px solid #fde68a; border-radius: 6px; padding: 0.4rem 0.75rem; }
        .diffstat-added { color: #16a34a; font-weight: 600; margin-right: 0.5rem; }
        .diffstat-removed { color: #b91c1c; font-weight: 600; }
        .compare-form { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .success { color: #15803d; } .story-slider { background: linear-gradient(135deg, #1f2430, #334155); border-radius: 6px; padding: 2.25rem 2rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; } .story-slides { position: relative; min-height: 9rem; } .story-slide { position: absolute; inset: 0; margin: 0; opacity: 0; transition: opacity 1s ease-in-out; pointer-events: none; } .story-slide.is-active { opacity: 1; pointer-events: auto; } .story-slide blockquote { margin: 0 0 0.75rem 0; color: #f1f5f9; font-size: 1.15rem; line-height: 1.6; font-style: italic; } .story-slide figcaption { color: #94a3b8; font-size: 0.85rem; } .story-slide figcaption a { color: #cbd5e1; } .story-slide-book { font-weight: 600; } .story-slide-sep { margin: 0 0.35rem; } .story-slider[data-count="1"] .story-slide { position: static; } .story-slider-nav { position: absolute; bottom: 0.75rem; right: 1rem; display: flex; gap: 0.4rem; } .story-slider-nav button { background: transparent; border: 1px solid #475569; color: #94a3b8; width: 1.6rem; height: 1.6rem; padding: 0; border-radius: 50%; font-size: 0.9rem; line-height: 1; display: flex; align-items: center; justify-content: center; cursor: pointer; } .story-slider-nav button.is-active { background: #94a3b8; color: #1f2430; border-color: #94a3b8; }
    </style>
    <link rel="stylesheet" href="/assets/css/editor.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/"><?= e(t('core', 'brand')) ?></a>
    <nav>
        <?php if (($user ?? null) !== null): ?>
            <?php if ($user->isAdmin()): ?>
                <a href="/pages"><?= e(t('core', 'nav_pages')) ?></a>
                <a href="/pages/new"><?= e(t('core', 'nav_new_page')) ?></a>
                <a href="/users"><?= e(t('core', 'nav_users')) ?></a>
            <?php endif; ?>
            <a href="/writing"><?= e(t('core', 'nav_writing')) ?></a>
            <a href="/profile"><?= e($user->username()) ?></a>
            <form class="inline" method="post" action="/logout">
                <?= ($csrf ?? null) !== null ? $csrf->field() : '' ?>
                <button type="submit"><?= e(t('core', 'nav_sign_out')) ?></button>
            </form>
        <?php else: ?>
            <a href="/login"><?= e(t('core', 'nav_sign_in')) ?></a>
        <?php endif; ?>
    </nav>
</header>
<main>
<?= $content ?>
</main>
<script src="/assets/js/editor.js" defer></script><script src="/assets/js/story-slider.js" defer></script>
</body>
</html>
