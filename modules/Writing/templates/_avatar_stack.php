<?php
/**
 * Shared avatar stack partial (prompt 24). Expects:
 *   $stackMembers = list of member rows (username, user_id, avatar)
 *   $stackHref    = ?string link target (project setup page for the owner)
 * Renders up to 3 avatar circles + a "+N" counter; title = all names,
 * aria-label = translated "Shared with {names} ({count}) - manage sharing".
 * With $stackHref it is a link, otherwise a plain span (never nested links).
 */
$stackShown = array_slice($stackMembers, 0, 3);
$stackHidden = count($stackMembers) - count($stackShown);
$stackNames = implode(', ', array_map(static fn (array $m): string => $m['username'], $stackMembers));
$stackLabel = t('writing', 'shared_with_manage', ['names' => $stackNames, 'count' => (string)count($stackMembers)]);
$stackInner = '';
foreach ($stackShown as $m) {
    $stackInner .= avatar_tag((string)$m['username'], (string)$m['user_id'], $m['avatar'] ?? null);
}
if ($stackHidden > 0) {
    $stackInner .= '<span class="avatar avatar-more" title="' . e(t('writing', 'more_members', ['n' => (string)$stackHidden])) . '">+' . $stackHidden . '</span>';
}
if ($stackHref !== null) {
    echo '<a class="avatar-stack share-stack" href="' . e($stackHref) . '" title="' . e($stackNames) . '" aria-label="' . e($stackLabel) . '">' . $stackInner . '</a>';
} else {
    echo '<span class="avatar-stack" title="' . e($stackNames) . '" aria-label="' . e($stackLabel) . '">' . $stackInner . '</span>';
}
