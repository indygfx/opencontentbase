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
    <p class="muted"><a href="/writing">&larr; <?= e(t('writing', 'all_stories')) ?></a></p>
    <h1><?= e($project['title']) ?></h1>
    <?php if ((string)($project['cover'] ?? '') !== ''): ?>
        <p><img class="cover-preview" src="/writing/<?= e($project['id']) ?>/cover" alt="<?= e(t('writing', 'cover_alt', ['title' => (string)$project['title']])) ?>"></p>
    <?php endif; ?>
    <div class="progress progress--overall" data-section="overall">
        <div class="progress-track"><div class="progress-bar<?= $overall >= 1.0 ? ' is-done' : ' is-partial' ?>" style="width: <?= (int)round($overall * 100) ?>%"></div></div>
        <span class="progress-label"><?= number_format($overall * 100) ?>% <?= e(t('writing', 'overall')) ?></span>
    </div>
    <?php if ($isOwner): ?>
        <p><a class="button" href="/writing/<?= e($project['id']) ?>/setup"><?= e(t('writing', 'novel_setup')) ?></a></p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/export"><?= e(t('writing', 'export_story')) ?></a></p>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2><?= e(t('writing', 'idea')) ?></h2>
    <p class="muted"><?= $progress['idea']['done'] ? '&#10003; ' . e(t('writing', 'set')) : '&mdash; ' . e(t('writing', 'missing')) ?></p>
    <p class="muted"><?= e(t('writing', 'idea_hint')) ?></p>
    <?php if ((string)$project['idea'] !== ''): ?>
        <p><?= e(firstSentence((string)$project['idea'])) ?></p>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'not_written_yet')) ?></p>
    <?php endif; ?>
    <?php if ($isOwner): ?>
        <p><a href="/writing/<?= e($project['id']) ?>/outline/idea"><?= e(t('writing', 'edit')) ?></a></p>
    <?php endif; ?>

    <h2><?= e(t('writing', 'blurb')) ?></h2>
    <?= progressBar('blurb', $progress['blurb'], number_format($progress['blurb']['actual']) . ' / ' . number_format($progress['blurb']['target']) . ' ' . t('writing', 'words')) ?>
    <p class="muted"><?= e(t('writing', 'blurb_hint')) ?></p>
    <?php if ((string)$project['blurb'] !== ''): ?>
        <p><?= e(clampWords((string)$project['blurb'])) ?></p>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'not_written_yet')) ?></p>
    <?php endif; ?>
    <?php if ($isOwner): ?>
        <p><a href="/writing/<?= e($project['id']) ?>/outline/blurb"><?= e(t('writing', 'edit')) ?></a></p>
    <?php endif; ?>


    <h2><?= e(t('writing', 'synopsis_long')) ?></h2>
    <?= progressBar('synopsis', $progress['synopsis'], number_format($progress['synopsis']['actual']) . ' / ' . number_format($progress['synopsis']['target']) . ' ' . t('writing', 'words')) ?>
    <p class="muted"><?= e(t('writing', 'synopsis_long_hint')) ?></p>
    <?php if ((string)$project['synopsis_long'] !== ''): ?>
        <p><?= e(clampWords((string)$project['synopsis_long'])) ?></p>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'not_written_yet')) ?></p>
    <?php endif; ?>
    <?php if ($isOwner): ?>
        <p><a href="/writing/<?= e($project['id']) ?>/outline/synopsis_long"><?= e(t('writing', 'edit')) ?></a></p>
    <?php endif; ?>



    <h2><?= e(t('writing', 'chapter_outlines')) ?></h2>
    <?= progressBar('outlines', $progress['outlines'], $progress['outlines']['actual'] . ' / ' . $progress['outlines']['target'] . ' ' . t('writing', 'chapters')) ?>
    <p class="muted"><?= e(t('writing', 'chapter_outlines_hint')) ?></p>
    <?php if ($synopses !== []): ?>
        <table>
            <tr><th>#</th><th><?= e(t('writing', 'outline')) ?></th><th><?= e(t('writing', 'summary')) ?></th><th><?= e(t('writing', 'prose')) ?></th><th></th></tr>
            <?php foreach ($synopses as $i => $s): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/synopses/<?= e($s['slug']) ?>"><?= e($s['title']) ?></a></td>
                    <td class="muted"><?= e(mb_strimwidth((string)$s['summary_text'], 0, 80, '…')) ?></td>
                    <td><?= $s['chapter_slug'] !== null
                        ? '<a href="/writing/' . e($project['id']) . '/chapters/' . e($s['chapter_slug']) . '">' . e(t('writing', 'written')) . '</a>'
                        : '<span class="muted">' . e(t('writing', 'open')) . '</span>' ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/synopses/<?= e($s['slug']) ?>/edit"><?= e(t('writing', 'edit')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'no_outlines_yet')) ?></p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/synopses/new"><?= e(t('writing', 'new_chapter_outline')) ?></a></p>

    <h2><?= e(t('writing', 'characters_header')) ?></h2>
    <?= progressBar('characters', $progress['characters'], $progress['characters']['actual'] . ' / ' . $progress['characters']['target'] . ' ' . t('writing', 'characters')) ?>
    <p class="muted"><?= e(t('writing', 'characters_hint')) ?></p>
    <?php if ($characters !== []): ?>
        <table>
            <tr><th><?= e(t('writing', 'name')) ?></th><th><?= e(t('pages', 'table_slug')) ?></th><th></th></tr>
            <?php foreach ($characters as $ch): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($ch['slug']) ?>"><?= e($ch['title']) ?></a></td>
                    <td class="muted"><?= e($ch['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($ch['slug']) ?>"><?= e(t('writing', 'open')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'no_characters_yet')) ?></p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/characters/new"><?= e(t('writing', 'new_character')) ?></a></p>

    <h2><?= e(t('writing', 'relationship_web')) ?> <a class="button" href="/writing/<?= e($project['id']) ?>/graph"><?= e(t('writing', 'graph_open')) ?></a></h2>
    <?= progressBar('relations', $progress['relations'], $progress['relations']['actual'] . ' / ' . $progress['relations']['target'] . ' ' . t('writing', 'relations')) ?>
    <p class="muted"><?= e(t('writing', 'relationship_web_hint')) ?></p>
    <?php if ($relations !== []): ?>
        <table>
            <tr><th><?= e(t('writing', 'from')) ?></th><th><?= e(t('writing', 'kind')) ?></th><th><?= e(t('writing', 'to')) ?></th><th><?= e(t('writing', 'conflict')) ?></th><th></th></tr>
            <?php foreach ($relations as $r): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($r['from_slug'] ?? '') ?>"><?= e($r['from_title']) ?></a></td>
                    <td><?= e($r['kind']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($r['to_slug'] ?? '') ?>"><?= e($r['to_title']) ?></a></td>
                    <td class="muted"><?= e($r['description']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/relations/<?= e($r['id']) ?>/edit"><?= e(t('writing', 'edit')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'no_relationships_yet')) ?></p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/relations/new"><?= e(t('writing', 'new_relationship')) ?></a></p>

    <h2><?= e(t('writing', 'backgrounds_header')) ?></h2>
    <?= progressBar('backgrounds', $progress['backgrounds'], $progress['backgrounds']['actual'] . ' / ' . $progress['backgrounds']['target'] . ' ' . t('writing', 'backgrounds')) ?>
    <p class="muted"><?= e(t('writing', 'backgrounds_hint')) ?></p>
    <?php if ($backgrounds !== []): ?>
        <table>
            <tr><th><?= e(t('pages', 'table_title')) ?></th><th><?= e(t('pages', 'table_slug')) ?></th><th></th></tr>
            <?php foreach ($backgrounds as $b): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/backgrounds/<?= e($b['slug']) ?>"><?= e($b['title']) ?></a></td>
                    <td class="muted"><?= e($b['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/backgrounds/<?= e($b['slug']) ?>"><?= e(t('writing', 'open')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'no_backgrounds_yet')) ?></p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/backgrounds/new"><?= e(t('writing', 'new_background')) ?></a></p>

    <h2><?= e(t('writing', 'written_chapters')) ?></h2>
    <?= progressBar('chapters', $progress['chapters'], number_format($progress['chapters']['actual']) . ' / ' . number_format($progress['chapters']['target']) . ' ' . t('writing', 'words')) ?>
    <p class="muted"><?= e(t('writing', 'written_chapters_hint')) ?></p>
    <?php if ($chapters !== []): ?>
        <table>
            <tr><th>#</th><th><?= e(t('writing', 'chapter')) ?></th><th></th></tr>
            <?php foreach ($chapters as $i => $ch): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>"><?= e($ch['title']) ?></a></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>/edit"><?= e(t('writing', 'open')) ?></a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted"><?= e(t('writing', 'no_written_chapters_yet')) ?></p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/chapters/new"><?= e(t('writing', 'new_chapter')) ?></a></p>

    <?php if (!$isOwner): ?>
        <p class="muted">Shared with you by <?= e($project['owner_name'] ?? '') ?>. You can read and edit texts, but not manage the story.</p>
    <?php endif; ?>
</section>
