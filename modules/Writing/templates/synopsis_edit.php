<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $isEdit ? 'Edit outline: ' . e($synopsis['title'] ?? '') : 'New chapter outline' ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $isEdit
        ? '/writing/' . e($projectId) . '/synopses/' . e($synopsis['slug'])
        : '/writing/' . e($projectId) . '/synopses' ?>">
        <?= $csrf->field() ?>
        <label for="title">Title</label>
        <input id="title" name="title" value="<?= e($synopsis['title'] ?? '') ?>" required maxlength="200">
        <label for="slug">Slug <small class="muted">(leave empty = derived from the title)</small></label>
        <input id="slug" name="slug" value="<?= e($synopsis['slug'] ?? '') ?>" placeholder="automatic" pattern="[a-z0-9-]*" title="a-z, 0-9, hyphen; leave empty to derive automatically" <?= $isEdit ? 'readonly' : '' ?>>
        <label for="summary">Summary <small class="muted">(optional &mdash; what happens in this chapter)</small></label>
        <textarea id="summary" name="summary" rows="6" placeholder="What happens in this chapter?"><?= e($synopsis['summary_text'] ?? '') ?></textarea>
        <button type="submit">Save</button>
    </form>
</section>
