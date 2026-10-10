<section class="card">
    <p class="muted"><a href="/writing/<?= e($project['id']) ?>">&larr; Back to the story</a></p>
    <h1>Novel Setup</h1>
    <p class="muted"><?= e($project['title']) ?></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Title</h2>
    <form method="post" action="/writing/<?= e($project['id']) ?>">
        <?= $csrf->field() ?>
        <label for="title">Title</label>
        <input id="title" name="title" required minlength="2" maxlength="200" value="<?= e($project['title']) ?>">
        <button type="submit">Save title</button>
    </form>

    <h2>Album cover</h2>
    <?php if ((string)$project['cover'] !== ''): ?>
        <p><img class="cover-preview" src="/writing/<?= e($project['id']) ?>/cover" alt="Album cover of <?= e($project['title']) ?>"></p>
        <form method="post" action="/writing/<?= e($project['id']) ?>/cover/delete" data-confirm="Remove the album cover?">
            <?= $csrf->field() ?>
            <button type="submit">Remove cover</button>
        </form>
    <?php endif; ?>
    <form method="post" action="/writing/<?= e($project['id']) ?>/cover" enctype="multipart/form-data">
        <?= $csrf->field() ?>
        <label for="cover">Cover image <small class="muted">(JPG, PNG, WebP or GIF, max 5 MB)</small></label>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp,image/gif" required>
        <button type="submit"><?= (string)$project['cover'] !== '' ? 'Replace cover' : 'Upload cover' ?></button>
    </form>
    <h2>Progress Targets</h2>
    <p class="muted">Measurable goals per section &mdash; the dashboard shows your progress against them.</p>
    <form method="post" action="/writing/<?= e($project['id']) ?>/targets">
        <?= $csrf->field() ?>
        <label for="genre">Genre <small class="muted">(sets a typical total word count)</small></label>
        <select id="genre" name="genre">
            <?php foreach ($genres as $g): ?>
                <option value="<?= e($g) ?>" <?= (string)($project['genre'] ?? 'General') === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="target_chapters">Planned chapters <small class="muted">(how many chapters the novel will have)</small></label>
        <input type="number" id="target_chapters" name="target_chapters" min="1" max="1000" value="<?= (int)$targets['target_chapters'] ?>" required>
        <label for="target_words_total">Total prose words <small class="muted">(adult novels: 80,000&ndash;100,000 words; below 70k feels thin, above 130k is hard to sell)</small></label>
        <input type="number" id="target_words_total" name="target_words_total" min="1" max="10000000" value="<?= (int)$targets['target_words_total'] ?>" required>
        <label for="target_words_blurb">Short description words <small class="muted">(the blurb counts as complete at this length)</small></label>
        <input type="number" id="target_words_blurb" name="target_words_blurb" min="1" max="10000" value="<?= (int)$targets['target_words_blurb'] ?>" required>
        <label for="target_words_synopsis">Extended synopsis words <small class="muted">(the full story line at this length)</small></label>
        <input type="number" id="target_words_synopsis" name="target_words_synopsis" min="1" max="100000" value="<?= (int)$targets['target_words_synopsis'] ?>" required>
        <label for="target_min_characters">Main characters <small class="muted">(how many figures carry the story)</small></label>
        <input type="number" id="target_min_characters" name="target_min_characters" min="1" max="100" value="<?= (int)$targets['target_min_characters'] ?>" required>
        <label for="target_min_relations">Relations per character <small class="muted">(each main figure should have this many connections)</small></label>
        <input type="number" id="target_min_relations" name="target_min_relations" min="1" max="100" value="<?= (int)$targets['target_min_relations'] ?>" required>
        <label for="target_backgrounds">Backgrounds <small class="muted">(one per category at least: history, geography, religion, politics)</small></label>
        <input type="number" id="target_backgrounds" name="target_backgrounds" min="1" max="100" value="<?= (int)$targets['target_backgrounds'] ?>" required>
        <button type="submit">Save targets</button>
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
    <h2>Share story</h2>
    <?php if ($shareTargets !== []): ?>
        <form method="post" action="/writing/<?= e($project['id']) ?>/share">
            <?= $csrf->field() ?>
            <label for="username">Username</label>
            <select id="username" name="username" required>
                <option value="" disabled selected>who to invite</option>
                <?php foreach ($shareTargets as $t): ?>
                    <option value="<?= e($t['username']) ?>"><?= e($t['username']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Share</button>
        </form>
    <?php else: ?>
        <p class="muted">There is no other user left to share this story with.</p>
    <?php endif; ?>

    <?php if ($members !== []): ?>
        <h3>Shared with</h3>
        <table>
            <tr><th>User</th><th>Role</th><th></th></tr>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['username']) ?></td>
                    <td><?= e($m['role']) ?></td>
                    <td>
                        <form class="inline" method="post" action="/writing/<?= e($project['id']) ?>/unshare">
                            <?= $csrf->field() ?>
                            <input type="hidden" name="user_id" value="<?= e($m['user_id']) ?>">
                            <button type="submit">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h2>Danger zone</h2>
    <form method="post" action="/writing/<?= e($project['id']) ?>/delete" data-confirm="Delete this story and all its content?">
        <?= $csrf->field() ?>
        <button type="submit">Delete story</button>
    </form>
</section>
