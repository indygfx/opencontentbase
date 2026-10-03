<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1>Chapter outline</h1>
    <p class="muted">A short outline of what happens in this chapter.</p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/summary">
        <?= $csrf->field() ?>
        <label for="title">Title</label>
        <input id="title" name="title" required maxlength="200" value="<?= e($chapter['title']) ?>">
        <label for="summary">Summary</label>
        <textarea id="summary" name="summary" rows="6" placeholder="What happens in this chapter? Write the idea first, the full text later."><?= e($chapter['summary']) ?></textarea>
        <button type="submit">Save outline</button>
    </form>
    <p class="muted"><a href="/writing/<?= e($projectId) ?>/chapters/<?= e($chapter['slug']) ?>/edit">Write the chapter itself &rarr;</a></p>
</section>
