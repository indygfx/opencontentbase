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
        .broken-link { color: #b91c1c; background: #fef2f2; border-bottom: 1px dashed #b91c1c; }
        .preview { margin-top: 2rem; }
        .muted { color: var(--muted); }
        .success { color: #15803d; }
    </style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">ContentBase</a>
    <nav>
        <?php if (($user ?? null) !== null): ?>
            <a href="/pages">Pages</a>
            <a href="/writing">Writing</a>
            <?php if ($user->isAdmin()): ?>
                <a href="/users">Users</a>
            <?php endif; ?>
            <?php if ($user->hasAtLeast('editor')): ?>
                <a href="/pages/new">New page</a>
            <?php endif; ?>
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
</body>
</html>
