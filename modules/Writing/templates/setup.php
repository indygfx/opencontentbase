<section class="card">
    <p class="muted"><a href="/writing/<?= e($project['id']) ?>">&larr; <?= e(t('writing', 'back_to_story')) ?></a></p>
    <h1><?= e(t('writing', 'novel_setup')) ?></h1>
    <p class="muted"><?= e($project['title']) ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2><?= e(t('pages', 'title_label')) ?></h2>
    <form method="post" action="/writing/<?= e($project['id']) ?>">
        <?= $csrf->field() ?>
        <label for="title"><?= e(t('pages', 'title_label')) ?></label>
        <input id="title" name="title" required minlength="2" maxlength="200" value="<?= e($project['title']) ?>">
        <button type="submit"><?= e(t('writing', 'save_title')) ?></button>
    </form>

    <h2><?= e(t('writing', 'album_cover')) ?></h2>
    <?php if ((string)$project['cover'] !== ''): ?>
        <p><img class="cover-preview" src="/writing/<?= e($project['id']) ?>/cover" alt="<?= e(t('writing', 'cover_alt', ['title' => (string)$project['title']])) ?>"></p>
        <form method="post" action="/writing/<?= e($project['id']) ?>/cover/delete" data-confirm="<?= e(t('writing', 'confirm_remove_cover')) ?>">
            <?= $csrf->field() ?>
            <button type="submit"><?= e(t('writing', 'remove_cover')) ?></button>
        </form>
    <?php endif; ?>
    <form method="post" action="/writing/<?= e($project['id']) ?>/cover" enctype="multipart/form-data">
        <?= $csrf->field() ?>
        <label for="cover"><?= e(t('writing', 'cover_image')) ?> <small class="muted"><?= e(t('writing', 'image_rules')) ?></small></label>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp,image/gif" required>
        <button type="submit"><?= (string)$project['cover'] !== '' ? e(t('writing', 'replace_cover')) : e(t('writing', 'upload_cover')) ?></button>
    </form>
    <h2><?= e(t('writing', 'progress_targets')) ?></h2>
    <p class="muted"><?= e(t('writing', 'progress_targets_hint')) ?></p>
    <form method="post" action="/writing/<?= e($project['id']) ?>/targets">
        <?= $csrf->field() ?>
        <label for="genre"><?= e(t('writing', 'genre')) ?> <small class="muted"><?= e(t('writing', 'genre_hint')) ?></small></label>
        <select id="genre" name="genre">
            <?php foreach ($genres as $g): ?>
                <option value="<?= e($g) ?>" <?= (string)($project['genre'] ?? 'General') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="target_chapters"><?= e(t('writing', 'planned_chapters')) ?> <small class="muted"><?= e(t('writing', 'planned_chapters_hint')) ?></small></label>
        <input type="number" id="target_chapters" name="target_chapters" min="1" max="1000" value="<?= (int)$targets['target_chapters'] ?>" required>
        <label for="target_words_total"><?= e(t('writing', 'total_words')) ?> <small class="muted"><?= e(t('writing', 'total_words_hint')) ?></small></label>
        <input type="number" id="target_words_total" name="target_words_total" min="1" max="10000000" value="<?= (int)$targets['target_words_total'] ?>" required>
        <label for="target_words_blurb"><?= e(t('writing', 'blurb_words')) ?> <small class="muted"><?= e(t('writing', 'blurb_words_hint')) ?></small></label>
        <input type="number" id="target_words_blurb" name="target_words_blurb" min="1" max="10000" value="<?= (int)$targets['target_words_blurb'] ?>" required>
        <label for="target_words_synopsis"><?= e(t('writing', 'synopsis_words')) ?> <small class="muted"><?= e(t('writing', 'synopsis_words_hint')) ?></small></label>
        <input type="number" id="target_words_synopsis" name="target_words_synopsis" min="1" max="100000" value="<?= (int)$targets['target_words_synopsis'] ?>" required>
        <label for="target_min_characters"><?= e(t('writing', 'main_characters')) ?> <small class="muted"><?= e(t('writing', 'main_characters_hint')) ?></small></label>
        <input type="number" id="target_min_characters" name="target_min_characters" min="1" max="100" value="<?= (int)$targets['target_min_characters'] ?>" required>
        <label for="target_min_relations"><?= e(t('writing', 'relations_per_character')) ?> <small class="muted"><?= e(t('writing', 'relations_per_character_hint')) ?></small></label>
        <input type="number" id="target_min_relations" name="target_min_relations" min="1" max="100" value="<?= (int)$targets['target_min_relations'] ?>" required>
        <label for="target_backgrounds"><?= e(t('writing', 'backgrounds_header')) ?> <small class="muted"><?= e(t('writing', 'backgrounds_target_hint')) ?></small></label>
        <input type="number" id="target_backgrounds" name="target_backgrounds" min="1" max="100" value="<?= (int)$targets['target_backgrounds'] ?>" required>
        <button type="submit"><?= e(t('writing', 'save_targets')) ?></button>
    </form>
    <script>
    (function () {
        'use strict';
        var genreSelect = document.getElementById('genre');
        var wordsInput = document.getElementById('target_words_total');
        var byGenre = {
            'General': 90000,
            'Fantasy': 110000,
            'Sci-Fi': 100000,
            'Romance': 80000,
            'Thriller/Crime': 90000,
            'Young Adult': 70000
        };
        genreSelect.addEventListener('change', function () {
            var target = byGenre[genreSelect.value];
            if (typeof target === 'number' && wordsInput !== null) {
                wordsInput.value = target;
            }
        });
    })();
    </script>
    <h2><?= e(t('writing', 'share_story')) ?></h2>
    <?php if ($shareTargets !== []): ?>
        <form method="post" action="/writing/<?= e($project['id']) ?>/share">
            <?= $csrf->field() ?>
            <label for="username"><?= e(t('core', 'login_username')) ?></label>
            <select id="username" name="username" required>
                <option value="" disabled selected><?= e(t('writing', 'who_to_invite')) ?></option>
                <?php foreach ($shareTargets as $t): ?>
                    <option value="<?= e($t['username']) ?>"><?= e($t['username']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?= e(t('writing', 'share')) ?></button>
        </form>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'no_share_targets')) ?></p>
    <?php endif; ?>

    <?php if ($members !== []): ?>
        <h3><?= e(t('writing', 'shared_with')) ?></h3>
        <table>
            <tr><th><?= e(t('core', 'profile_username')) ?></th><th><?= e(t('core', 'users_role')) ?></th><th></th></tr>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['username']) ?></td>
                    <td><?= e($m['role']) ?></td>
                    <td>
                        <form class="inline" method="post" action="/writing/<?= e($project['id']) ?>/unshare">
                            <?= $csrf->field() ?>
                            <input type="hidden" name="user_id" value="<?= e($m['user_id']) ?>">
                            <button type="submit"><?= e(t('writing', 'revoke')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2><?= e(t('writing', 'danger_zone')) ?></h2>
    <form method="post" action="/writing/<?= e($project['id']) ?>/delete" data-confirm="<?= e(t('writing', 'confirm_delete_story')) ?>">
        <?= $csrf->field() ?>
        <button type="submit"><?= e(t('writing', 'delete_story')) ?></button>
    </form>
</section>
