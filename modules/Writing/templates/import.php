<section class="card narrow">
    <h1><?= e(t('writing', 'import_title')) ?></h1>
    <p class="muted"><?= e(t('writing', 'import_intro')) ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="success"><?= e($success) ?></p>
    <?php endif; ?>
    <form method="post" action="/writing/import" enctype="multipart/form-data">
        <?= $csrf->field() ?>
        <label for="bundle"><?= e(t('writing', 'import_label')) ?></label>
        <input type="file" id="bundle" name="bundle" accept=".zip,application/zip" required>
        <button type="submit"><?= e(t('writing', 'import_submit')) ?></button>
    </form>
</section>
