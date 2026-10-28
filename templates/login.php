<section class="card narrow">
    <h1><?= e(t('core', 'login_title')) ?></h1>
    <?php if (!empty($success)): ?>
        <p class="success"><?= e($success) ?></p>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/login">
        <?= ($csrf ?? null) !== null ? $csrf->field() : '' ?>
        <label for="username"><?= e(t('core', 'login_username')) ?></label>
        <input id="username" name="username" required autofocus autocomplete="username">
        <label for="password"><?= e(t('core', 'login_password')) ?></label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <button type="submit"><?= e(t('core', 'login_submit')) ?></button>
    </form>
</section>
