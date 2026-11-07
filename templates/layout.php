<?php $ta = theme_assets($user ?? null); ?>
<!DOCTYPE html>
<html lang="<?= e(Core\Translator::instance()?->lang() ?? Core\Translator::FALLBACK) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'ContentBase') ?></title>
    <link rel="stylesheet" href="/assets/css/master.css">
    <link rel="stylesheet" href="/assets/css/editor.css">
    <?php if ($ta['themeCss'] !== null): ?>
    <link rel="stylesheet" href="<?= e($ta['themeCss']) ?>?v=<?= (int)filemtime($ta['themeCssAbs']) ?>">
    <?php endif; ?>
    <?php if ($ta['overridesCss'] !== null): ?>
    <link rel="stylesheet" href="<?= e($ta['overridesCss']) ?>?v=<?= (int)filemtime($ta['overridesCssAbs']) ?>">
    <?php endif; ?>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="<?= e($ta['themeColor']) ?>">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
</head>
<body data-update-available="<?= e(t('core', 'update_available')) ?>" data-reload-now="<?= e(t('core', 'reload_now')) ?>">
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
<?php if ($ta['themeJs'] !== null): ?><script src="<?= e($ta['themeJs']) ?>?v=<?= (int)filemtime($ta['themeJsAbs']) ?>" defer></script><?php endif; ?>
<?php if ($ta['overridesJs'] !== null): ?><script src="<?= e($ta['overridesJs']) ?>?v=<?= (int)filemtime($ta['overridesJsAbs']) ?>" defer></script><?php endif; ?>
</body>
</html>
