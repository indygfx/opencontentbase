<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <?php if (!empty($imageError)): ?>
        <p class="error"><?= e($imageError) ?></p>
    <?php endif; ?>
    <?php if (($character['image_path'] ?? null) !== null && (string)$character['image_path'] !== ''): ?>
        <img class="character-portrait" src="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/image"
             alt="Portrait of <?= e($character['title'] ?? $character['slug']) ?>">
    <?php endif; ?>
    <article>
        <h1><?= e($character['title'] ?? $character['slug']) ?></h1>
        <div class="content"><?= $rendered /* already escaped/sanitized by ContentRenderer */ ?></div>
    </article>
    <?php if ($canEdit): ?>
        <h2>Character image</h2>
        <?php if (($character['image_path'] ?? null) !== null && (string)$character['image_path'] !== ''): ?>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/image/delete" data-confirm="Remove the character image?">
                <?= $csrf->field() ?>
                <button type="submit">Remove image</button>
            </form>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/image">
            <?= $csrf->field() ?>
            <label for="image">Upload portrait (JPG, PNG, WebP, GIF, max 5 MB)</label>
            <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
            <button type="submit"><?= (($character['image_path'] ?? null) !== null && (string)$character['image_path'] !== '') ? 'Replace image' : 'Upload image' ?></button>
        </form>
        <p>
            <a href="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/edit">Edit</a>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/delete" data-confirm="Delete this character?">
                <?= $csrf->field() ?>
                <button type="submit">Delete</button>
            </form>
        </p>
    <?php endif; ?>
</section>
