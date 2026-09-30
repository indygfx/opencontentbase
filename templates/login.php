<section class="card narrow">
    <h1>Sign in</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/login">
        <?= ($csrf ?? null) !== null ? $csrf->field() : '' ?>
        <label for="username">Username</label>
        <input id="username" name="username" required autofocus autocomplete="username">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <button type="submit">Sign in</button>
    </form>
</section>
