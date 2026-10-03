<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= $relation !== null && ($relation['id'] ?? null) !== null ? 'Edit relationship' : 'New relationship' ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($notice)): ?>
        <p class="muted"><?= e($notice) ?></p>
    <?php else: ?>
        <form method="post" action="<?= ($relation['id'] ?? null) !== null
            ? '/writing/' . e($projectId) . '/relations/' . e($relation['id'])
            : '/writing/' . e($projectId) . '/relations' ?>">
            <?= $csrf->field() ?>
            <label for="from_character_id">From</label>
            <select id="from_character_id" name="from_character_id" required>
                <?php foreach ($characterOptions as $c): ?>
                    <option value="<?= e($c['id']) ?>" <?= ($relation['from_character_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= e($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="kind">Kind</label>
            <select id="kind" name="kind">
                <?php foreach ($kinds as $k): ?>
                    <option value="<?= e($k) ?>" <?= ($relation['kind'] ?? 'related') === $k ? 'selected' : '' ?>><?= e($k) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="to_character_id">To</label>
            <select id="to_character_id" name="to_character_id" required>
                <?php foreach ($characterOptions as $c): ?>
                    <option value="<?= e($c['id']) ?>" <?= ($relation['to_character_id'] ?? '') === $c['id'] ? 'selected' : '' ?>><?= e($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="description">Conflict / description <small class="muted">(optional)</small></label>
            <input id="description" name="description" maxlength="500" value="<?= e($relation['description'] ?? '') ?>" placeholder="what stands between them">
            <button type="submit">Save</button>
        </form>
    <?php endif; ?>
</section>
