<section class="card">
    <p class="muted"><a href="/writing/<?= e($projectId) ?>">&larr; <?= e($projectTitle) ?></a></p>
    <h1><?= e(t('writing', 'graph_title')) ?></h1>

    <?php if ($characterCount === 0): ?>
        <p class="muted"><?= e(t('writing', 'graph_empty')) ?></p>
        <p><a class="button" href="/writing/<?= e($projectId) ?>/characters/new"><?= e(t('writing', 'graph_create_first_character')) ?></a></p>
    <?php elseif ($characterCount === 1 && $relationCount === 0): ?>
        <p class="muted"><?= e(t('writing', 'graph_single')) ?></p>
    <?php endif; ?>

    <?php if ($svg !== ''): ?>
        <div class="graph-wrap"><?= $svg /* built by RelationshipGraph, all dynamic parts escaped */ ?></div>
    <?php endif; ?>

    <?php if ($characterCount > 1): ?>
        <h2><?= e(t('writing', 'graph_legend')) ?></h2>
        <ul class="graph-legend">
            <?php foreach ($kindColors as $kind => $color): ?>
                <li><span class="graph-legend-swatch" style="background: <?= e($color) ?>"></span><?= e(t('writing', 'kind_' . $kind)) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="muted"><?= e(t('writing', 'graph_level_explanation')) ?></p>
        <p>
            <a href="/writing/<?= e($projectId) ?>#relationships"><?= e(t('writing', 'graph_back_to_relationships')) ?></a>
        </p>
    <?php endif; ?>
</section>
