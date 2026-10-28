<section class="card narrow">
    <h1><?= e(t('core', 'setup_title')) ?></h1>
    <p class="muted"><?= e(t('core', 'setup_intro')) ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/setup">
        <?= $csrf->field() ?>
        <label for="username"><?= e(t('core', 'login_username')) ?></label>
        <input id="username" name="username" required minlength="3" autofocus autocomplete="off">
        <label for="password"><?= e(t('core', 'login_password')) ?></label>
        <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
        <label for="password_confirm"><?= e(t('core', 'profile_confirm_password')) ?></label>
        <input id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
        <button type="submit"><?= e(t('core', 'setup_submit')) ?></button>
    </form>
</section>
