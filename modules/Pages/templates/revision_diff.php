<section class="card">
    <p class="muted">
        <a href="/pages/<?= e($page['slug']) ?>/revisions">&larr; <?= e(t('pages', 'history')) ?></a>
    </p>
    <h1><?= e(t('pages', 'history_diff_title', ['title' => (string)$page['title']])) ?></h1>
    <p class="muted">
        <?= e(t('pages', 'history_diff_from', ['author' => $from['author'], 'when' => $from['created_at']])) ?>
        &rarr;
        <?= e(t('pages', 'history_diff_to', ['author' => $to['author'], 'when' => $to['created_at']])) ?>
    </p>

    <?php if ($titleChanged): ?>
        <p class="diff-title-change">
            <?= e(t('pages', 'history_title_changed', ['from' => $from['title'], 'to' => $to['title']])) ?>
        </p>
    <?php endif; ?>

    <div class="diff">
        <?php
        $lines = [];
        foreach ($segments as $segment) {
            $segmentLines = $segment['text'] === '' ? [] : explode("\n", $segment['text']);
            foreach ($segmentLines as $line) {
                $lines[] = ['type' => $segment['type'], 'text' => $line];
            }
        }
        $changedIdx = [];
        foreach ($lines as $i => $line) {
            if ($line['type'] !== 'same') {
                $changedIdx[] = $i;
            }
        }
        $CONTEXT = 3;
        $visible = [];
        foreach ($changedIdx as $idx) {
            for ($j = max(0, $idx - $CONTEXT); $j <= min(count($lines) - 1, $idx + $CONTEXT); $j++) {
                $visible[$j] = true;
            }
        }
        $visibleKeys = array_keys($visible);
        ?>
        <?php if ($lines === []): ?>
            <p class="muted"><?= e(t('pages', 'history_no_changes')) ?></p>
        <?php elseif ($visibleKeys === [] || count($visibleKeys) === count($lines)): ?>
            <pre class="diff-body"><?php foreach ($lines as $line): ?><span
                    class="diff-line diff-<?= e($line['type']) ?>"><?= e($line['type'] === 'added' ? '+ ' : ($line['type'] === 'removed' ? '- ' : '  ')) ?><?= e($line['text']) ?></span>
<?php endforeach; ?></pre>
        <?php else: ?>
            <?php
            $blocks = [];
            $current = null;
            foreach (array_keys($lines) as $i) {
                $isVisible = isset($visible[$i]);
                if ($isVisible) {
                    if ($current === null) {
                        $current = ['from' => $i, 'to' => $i];
                        $blocks[] = $current;
                    } else {
                        $current['to'] = $i;
                    }
                } else {
                    $current = null;
                }
            }
            $lastIndex = count($blocks) - 1;
            ?>
            <?php foreach ($blocks as $blockIndex => $block): ?>
                <?php if ($blockIndex > 0): ?>
                    <details class="diff-collapse">
                        <summary><?= e(t('pages', 'history_show_all')) ?></summary>
                        <pre class="diff-body"><?php for ($k = $blocks[$blockIndex - 1]['to'] + 1; $k < $block['from']; $k++): ?><span
                                class="diff-line diff-same">  <?= e($lines[$k]['text']) ?></span>
<?php endfor; ?></pre>
                    </details>
                <?php endif; ?>
                <pre class="diff-body"><?php for ($k = $block['from']; $k <= $block['to']; $k++): ?><span
                        class="diff-line diff-<?= e($lines[$k]['type']) ?>"><?= e($lines[$k]['type'] === 'added' ? '+ ' : ($lines[$k]['type'] === 'removed' ? '- ' : '  ')) ?><?= e($lines[$k]['text']) ?></span>
<?php endfor; ?></pre>
                <?php if ($blockIndex === $lastIndex && $block['to'] < count($lines) - 1): ?>
                    <details class="diff-collapse">
                        <summary><?= e(t('pages', 'history_show_all')) ?></summary>
                        <pre class="diff-body"><?php for ($k = $block['to'] + 1; $k < count($lines); $k++): ?><span
                                class="diff-line diff-same">  <?= e($lines[$k]['text']) ?></span>
<?php endfor; ?></pre>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
