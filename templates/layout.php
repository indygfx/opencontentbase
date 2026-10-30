<!DOCTYPE html>
<html lang="<?= e(Core\Translator::instance()?->lang() ?? Core\Translator::FALLBACK) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'ContentBase') ?></title>
    <link rel="stylesheet" href="/assets/css/master.css">
    <?php if (file_exists(dirname(__DIR__) . '/public/assets/css/theme.css')): ?>
    <link rel="stylesheet" href="/assets/css/theme.css">
    <?php endif; ?>
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
<script src="/assets/js/app.js" defer></script><script src="/assets/js/editor.js" defer></script><script src="/assets/js/story-slider.js" defer></script>
</body>
</html>
