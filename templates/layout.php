<!DOCTYPE html>
<html lang="de">
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
        .progress { display: flex; align-items: center; gap: 0.6rem; margin: 0.25rem 0 0.75rem 0; }
        .progress-track { flex: 1 1 auto; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden; }
        .progress-bar { height: 100%; background: #f59e0b; border-radius: 3px; }
        .progress-bar.is-done { background: #16a34a; }
        .progress-bar.is-partial { background: #f59e0b; }
        .progress-label { flex: 0 0 auto; font-size: 0.75rem; color: var(--muted); text-align: right; }
        .progress--overall { margin-bottom: 1.25rem; }
        .success { color: #15803d; }
    </style>
    <link rel="stylesheet" href="/assets/css/editor.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">ContentBase</a>
    <nav>
        <?php if (($user ?? null) !== null): ?>
            <?php if ($user->isAdmin()): ?>
                <a href="/pages">Pages</a>
                <a href="/pages/new">New page</a>
                <a href="/users">Users</a>
            <?php endif; ?>
            <a href="/writing">Writing</a>
            <a href="/profile"><?= e($user->username()) ?></a>
            <form class="inline" method="post" action="/logout">
                <?= ($csrf ?? null) !== null ? $csrf->field() : '' ?>
                <button type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <a href="/login">Sign in</a>
        <?php endif; ?>
    </nav>
</header>
<main>
<?= $content ?>
</main>
<script src="/assets/js/editor.js" defer></script>
</body>
</html>
