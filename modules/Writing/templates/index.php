<?php if (($slides ?? []) !== []): ?>
<section class="story-slider" data-count="<?= count($slides) ?>" aria-label="<?= e(t('writing', 'slider_label')) ?>">
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
                <button type="button" data-slide="<?= $i ?>"<?= $i === 0 ? ' class="is-active"' : '' ?> aria-label="<?= e(t('writing', 'slider_excerpt', ['n' => (string)($i + 1)])) ?>"><?= $i + 1 ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="card">
    <h1><?= e(t('writing', 'stories_title')) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2><?= e(t('writing', 'new_story')) ?></h2>
    <form method="post" action="/writing">
        <?= $csrf->field() ?>
        <label for="title"><?= e(t('pages', 'title_label')) ?></label>
        <input id="title" name="title" required minlength="2" maxlength="200" placeholder="<?= e(t('writing', 'my_novel_placeholder')) ?>" autofocus>
        <button type="submit"><?= e(t('writing', 'create_story')) ?></button>
    </form>

    <h2><?= e(t('writing', 'my_stories')) ?></h2>
    <?php if ($projects === []): ?>
        <p class="muted"><?= e(t('writing', 'no_stories')) ?></p>
    <?php else: ?>
        <table>
            <tr>
                <th><?= e(t('pages', 'table_title')) ?></th>
                <th><?= e(t('writing', 'owner')) ?></th>
                <th><?= e(t('writing', 'created')) ?></th>
                <th></th>
            </tr>
            <?php foreach ($projects as $p): ?>
                <tr>
                    <td><a href="/writing/<?= e($p['id']) ?>"><?= e($p['title']) ?></a></td>
                    <td>
                        <?= e($p['owner_name']) ?>
                        <?php if ((int)$p['shared'] === 1): ?>
                            <span class="muted"><?= e(t('writing', 'shared_with_you')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= e((string)$p['created_at']) ?></td>
                    <td><a href="/writing/<?= e($p['id']) ?>"><?= e(t('writing', 'open')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</section>
