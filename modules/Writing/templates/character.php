<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <article>
        <h1><?= e($character['title'] ?? $character['slug']) ?></h1>
        <div class="content"><?= $rendered /* already escaped/sanitized by ContentRenderer */ ?></div>
    </article>
    <?php if ($canEdit): ?>
        <p>
            <a href="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/edit">Edit</a>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/characters/<?= e($character['slug']) ?>/delete" data-confirm="Delete this character?">
                <?= $csrf->field() ?>
                <button type="submit">Delete</button>
            </form>
        </p>
    <?php endif; ?>
</section>
