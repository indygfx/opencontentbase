<?php

/**
 * @param string $text
 * @return string first sentence of a text
 */
function firstSentence(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (preg_match('/^(.*?[.!?])(?:\s|$)/us', $text, $m) === 1) {
        return $m[1];
    }
    return $text;
}

/**
 * @param string $text
 * @return string text clamped to 250 words
 */
function clampWords(string $text, int $max = 250): string
{
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if ($words === false || count($words) <= $max) {
        return trim($text);
    }
    return implode(' ', array_slice($words, 0, $max)) . ' …';
}
?>
<?php
/**
 * @param array{ratio: float, actual: int, target: int, done: bool} $entry
 */
function progressBar(string $section, array $entry, string $label): string
{
    $percent = (int)round($entry['ratio'] * 100);
    $class = $entry['done'] ? ' is-done' : ($entry['ratio'] > 0.0 ? ' is-partial' : '');
    return '<div class="progress" data-section="' . e($section) . '">'
        . '<div class="progress-track"><div class="progress-bar' . $class . '" style="width: ' . $percent . '%"></div></div>'
        . '<span class="progress-label">' . e($label) . '</span>'
        . '</div>';
}
?>
<section class="card">
    <p class="muted"><a href="/writing">&larr; All stories</a></p>
    <h1><?= e($project['title']) ?></h1>
    <?php if ((string)($project['cover'] ?? '') !== ''): ?>
        <p><img class="cover-preview" src="/writing/<?= e($project['id']) ?>/cover" alt="Album cover of <?= e($project['title']) ?>"></p>
    <?php endif; ?>
    <div class="progress progress--overall" data-section="overall">
        <div class="progress-track"><div class="progress-bar<?= $overall >= 1.0 ? ' is-done' : ' is-partial' ?>" style="width: <?= (int)round($overall * 100) ?>%"></div></div>
        <span class="progress-label"><?= number_format($overall * 100) ?>% overall</span>
    </div>
    <?php if ($isOwner): ?>
        <p><a class="button" href="/writing/<?= e($project['id']) ?>/setup">Novel Setup</a></p>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Idea</h2>
    <p class="muted"><?= $progress['idea']['done'] ? '&#10003; set' : '&mdash; missing' ?></p>
    <p class="muted">Your story's core in one sentence (logline).</p>
    <?php if ((string)$project['idea'] !== ''): ?>
        <p><?= e(firstSentence((string)$project['idea'])) ?></p>
    <?php else: ?>
        <p class="muted">Not written yet.</p>
    <?php endif; ?>
    <?php if ($isOwner): ?>
        <p><a href="/writing/<?= e($project['id']) ?>/outline/idea">Edit</a></p>
    <?php endif; ?>

    <h2>Short Description</h2>
    <?= progressBar('blurb', $progress['blurb'], number_format($progress['blurb']['actual']) . ' / ' . number_format($progress['blurb']['target']) . ' words') ?>
    <p class="muted">Your story in one paragraph: setup, three turning points, ending.</p>
    <?php if ((string)$project['blurb'] !== ''): ?>
        <p><?= e(clampWords((string)$project['blurb'])) ?></p>
    <?php else: ?>
        <p class="muted">Not written yet.</p>
    <?php endif; ?>
    <?php if ($isOwner): ?>
        <p><a href="/writing/<?= e($project['id']) ?>/outline/blurb">Edit</a></p>
    <?php endif; ?>


    <h2>Extended Synopsis</h2>
    <?= progressBar('synopsis', $progress['synopsis'], number_format($progress['synopsis']['actual']) . ' / ' . number_format($progress['synopsis']['target']) . ' words') ?>
    <p class="muted">Think about the course of your story. Describe the whole story line in one detailed draft.</p>
    <?php if ((string)$project['synopsis_long'] !== ''): ?>
        <p><?= e(clampWords((string)$project['synopsis_long'])) ?></p>
    <?php else: ?>
        <p class="muted">Not written yet.</p>
    <?php endif; ?>
    <?php if ($isOwner): ?>
        <p><a href="/writing/<?= e($project['id']) ?>/outline/synopsis_long">Edit</a></p>
    <?php endif; ?>



    <h2>Chapter Outlines</h2>
    <?= progressBar('outlines', $progress['outlines'], $progress['outlines']['actual'] . ' / ' . $progress['outlines']['target'] . ' chapters') ?>
    <p class="muted">The plan for each chapter &mdash; title and a short summary of what happens.</p>
    <?php if ($synopses !== []): ?>
        <table>
            <tr><th>#</th><th>Outline</th><th>Summary</th><th>Prose</th><th></th></tr>
            <?php foreach ($synopses as $i => $s): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/synopses/<?= e($s['slug']) ?>"><?= e($s['title']) ?></a></td>
                    <td class="muted"><?= e(mb_strimwidth((string)$s['summary_text'], 0, 80, '…')) ?></td>
                    <td><?= $s['chapter_slug'] !== null
                        ? '<a href="/writing/' . e($project['id']) . '/chapters/' . e($s['chapter_slug']) . '">written</a>'
                        : '<span class="muted">open</span>' ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/synopses/<?= e($s['slug']) ?>/edit">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No chapter outlines yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/synopses/new">New chapter outline</a></p>

    <h2>Characters</h2>
    <?= progressBar('characters', $progress['characters'], $progress['characters']['actual'] . ' / ' . $progress['characters']['target'] . ' characters') ?>
    <p class="muted">Give your characters a face &mdash; appearance, biography, motivation and the wounds that drive them.</p>
    <?php if ($characters !== []): ?>
        <table>
            <tr><th>Name</th><th>Slug</th><th></th></tr>
            <?php foreach ($characters as $ch): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($ch['slug']) ?>"><?= e($ch['title']) ?></a></td>
                    <td class="muted"><?= e($ch['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($ch['slug']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No characters yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/characters/new">New character</a></p>

    <h2>Relationship Web</h2>
    <?= progressBar('relations', $progress['relations'], $progress['relations']['actual'] . ' / ' . $progress['relations']['target'] . ' relations') ?>
    <p class="muted">Who knows whom, how they are connected &mdash; and where the conflict lies.</p>
    <?php if ($relations !== []): ?>
        <table>
            <tr><th>From</th><th>Kind</th><th>To</th><th>Conflict</th><th></th></tr>
            <?php foreach ($relations as $r): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($r['from_slug'] ?? '') ?>"><?= e($r['from_title']) ?></a></td>
                    <td><?= e($r['kind']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($r['to_slug'] ?? '') ?>"><?= e($r['to_title']) ?></a></td>
                    <td class="muted"><?= e($r['description']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/relations/<?= e($r['id']) ?>/edit">Edit</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No relationships yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/relations/new">New relationship</a></p>

    <h2>Backgrounds</h2>
    <?= progressBar('backgrounds', $progress['backgrounds'], $progress['backgrounds']['actual'] . ' / ' . $progress['backgrounds']['target'] . ' backgrounds') ?>
    <p class="muted">The world your story stands on &mdash; its historical, geographical, religious and political context.</p>
    <?php if ($backgrounds !== []): ?>
        <table>
            <tr><th>Title</th><th>Slug</th><th></th></tr>
            <?php foreach ($backgrounds as $b): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/backgrounds/<?= e($b['slug']) ?>"><?= e($b['title']) ?></a></td>
                    <td class="muted"><?= e($b['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/backgrounds/<?= e($b['slug']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No backgrounds yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/backgrounds/new">New background</a></p>

    <h2>Written Chapters</h2>
    <?= progressBar('chapters', $progress['chapters'], number_format($progress['chapters']['actual']) . ' / ' . number_format($progress['chapters']['target']) . ' words') ?>
    <p class="muted">The actual prose, chapter by chapter &mdash; written from your outlines above.</p>
    <?php if ($chapters !== []): ?>
        <table>
            <tr><th>#</th><th>Chapter</th><th></th></tr>
            <?php foreach ($chapters as $i => $ch): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>"><?= e($ch['title']) ?></a></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>/edit">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No written chapters yet. Pick an outline above and start writing.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/chapters/new">New chapter</a></p>

    <?php if (!$isOwner): ?>
        <p class="muted">Shared with you by <?= e($project['owner_name'] ?? '') ?>. You can read and edit texts, but not manage the story.</p>
    <?php endif; ?>
</section>
