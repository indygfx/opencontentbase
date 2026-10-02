<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <article>
        <h1><?= e($chapter['title'] ?? $chapter['slug']) ?></h1>
        <?php if (!empty($chapter['summary'])): ?>
            <p class="muted"><strong>Idee:</strong> <?= e($chapter['summary']) ?></p>
        <?php endif; ?>
        <div class="content"><?= $rendered /* already escaped/sanitized by ContentRenderer */ ?></div>
    </article>
    <?php if ($canEdit): ?>
        <p>
            <a href="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/edit">Edit</a>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/delete" data-confirm="Delete this chapter?">
                <?= $csrf->field() ?>
                <button type="submit">Delete</button>
            </form>
        </p>
    <?php endif; ?>
</section>
