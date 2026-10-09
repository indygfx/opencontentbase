<section class="card">
    <p class="muted"><a href="/writing/<?= e($project['id']) ?>">&larr; Back to the story</a></p>
    <h1><?= e($label) ?></h1>
    <p class="muted"><?= e($project['title']) ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/writing/<?= e($project['id']) ?>/outline/<?= e($field) ?>">
        <?= $csrf->field() ?>
        <label for="text"><?= e($label) ?> (Markdown, internal links as [[chapter:slug]])</label>
        <textarea id="text" name="text" rows="14"><?= e($text) ?></textarea>
        <button type="submit">Save</button>
    </form>
    <script>
    (function () {
        'use strict';
        var text = document.getElementById('text');
        document.addEventListener('keydown', function (ev) {
            if ((ev.metaKey || ev.ctrlKey) && ev.key === 's') {
                ev.preventDefault();
                text.form.submit();
            }
        });
    })();
    </script>
</section>
