<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1>Chapter outline</h1>
    <h2><?= e($synopsis['title']) ?></h2>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ((string)$synopsis['summary_text'] !== ''): ?>
        <p><?= nl2br(e((string)$synopsis['summary_text'])) ?></p>
    <?php else: ?>
        <p class="muted">No summary written yet.</p>
    <?php endif; ?>
    <?php if ($chapter !== null): ?>
        <p class="muted">Assigned to the written chapter
            <a href="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>"><?= e($chapter['title']) ?></a>.
        </p>
    <?php else: ?>
        <p class="muted">Not assigned to a written chapter yet.</p>
    <?php endif; ?>
    <?php if ($canEdit): ?>
        <p>
            <a href="/writing/<?= e($projectId) ?>/synopses/<?= e($synopsis['slug']) ?>/edit">Edit</a>
            <form class="inline" method="post" action="/writing/<?= e($projectId) ?>/synopses/<?= e($synopsis['slug']) ?>/delete" data-confirm="Delete this chapter outline?">
                <?= $csrf->field() ?>
                <button type="submit">Delete</button>
            </form>
        </p>
    <?php endif; ?>
</section>
