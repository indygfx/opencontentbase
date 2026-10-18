<?php if (($slides ?? []) !== []): ?>
<section class="story-slider" data-count="<?= count($slides) ?>" aria-label="Story excerpts">
    <div class="story-slides">
        <?php foreach ($slides as $i => $slide): ?>
            <figure class="story-slide<?= $i === 0 ? ' is-active' : '' ?>">
                <blockquote>&ldquo;<?= e($slide['text']) ?>&rdquo;</blockquote>
                <figcaption>
                    <span class="story-slide-book"><?= e($slide['project_title']) ?></span>
                    <span class="story-slide-sep">&middot;</span>
                    <a href="/writing/<?= e($slide['project_id']) ?>/chapters/<?= e($slide['chapter_slug']) ?>">
                        <?= e($slide['chapter_title']) ?>
                    </a>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php if (count($slides) > 1): ?>
        <div class="story-slider-nav">
            <?php foreach ($slides as $i => $slide): ?>
                <button type="button" data-slide="<?= $i ?>"<?= $i === 0 ? ' class="is-active"' : '' ?> aria-label="Show excerpt <?= $i + 1 ?>"><?= $i + 1 ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="card">
    <h1>Stories</h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>New story</h2>
    <form method="post" action="/writing">
        <?= $csrf->field() ?>
        <label for="title">Title</label>
        <input id="title" name="title" required minlength="2" maxlength="200" placeholder="My novel" autofocus>
        <button type="submit">Create story</button>
    </form>

    <h2>My stories</h2>
    <?php if ($projects === []): ?>
        <p class="muted">No stories yet. Create your first one above.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Title</th>
                <th>Owner</th>
                <th>Created</th>
                <th></th>
            </tr>
            <?php foreach ($projects as $p): ?>
                <tr>
                    <td><a href="/writing/<?= e($p['id']) ?>"><?= e($p['title']) ?></a></td>
                    <td>
                        <?= e($p['owner_name']) ?>
                        <?php if ((int)$p['shared'] === 1): ?>
                            <span class="muted">(shared with you)</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= e((string)$p['created_at']) ?></td>
                    <td><a href="/writing/<?= e($p['id']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</section>
