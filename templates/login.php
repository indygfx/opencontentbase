<section class="card narrow">
    <h1>Anmelden</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/login">
        <label for="username">Benutzername</label>
        <input id="username" name="username" required autofocus autocomplete="username">
        <label for="password">Passwort</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <button type="submit">Anmelden</button>
    </form>
</section>
