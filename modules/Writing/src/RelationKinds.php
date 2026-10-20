<?php
declare(strict_types=1);

namespace Writing;

/**
 * Single source of truth for the relationship kind taxonomy and its
 * color groups. Every consumer (form dropdown, validation, graph edges,
 * legend) reads from this class - no duplicated kind or group lists.
 *
 * Extension recipe (see README):
 * - Add a kind: one line in GROUPS + one lang line per language
 *   (kind_<key>); the parity test enforces completeness.
 * - Add a group: one GROUPS entry + one CSS class with a stroke color
 *   (layout.php) + kindgroup_<group> lang lines; the legend picks it up
 *   automatically.
 * - Guideline: max ~9 groups total for legend readability; prefer fitting
 *   a new kind into an existing group over creating new ones.
 * - Unknown/legacy kind values never break rendering: groupFor() maps
 *   them to the neutral 'other' group.
 */
final class RelationKinds
{
    /** Fine-grained relation kinds mapped to their color group. */
    public const GROUPS = [
        'family' => 'family',
        'friend' => 'alliance',
        'ally' => 'alliance',
        'lover' => 'romance',
        'rival' => 'conflict',
        'enemy' => 'conflict',
        'mentor' => 'mentorship',
        'related' => 'other',
    ];

    /** Color group => CSS class name (the actual colors live in the stylesheet). */
    public const CLASSES = [
        'family' => 'rel-family',
        'alliance' => 'rel-alliance',
        'romance' => 'rel-romance',
        'conflict' => 'rel-conflict',
        'mentorship' => 'rel-mentorship',
        'other' => 'rel-other',
    ];

    public const FALLBACK_GROUP = 'other';

    /** Color group of a kind; unknown kinds resolve to the fallback group. */
    public static function groupFor(string $kind): string
    {
        return self::GROUPS[$kind] ?? self::FALLBACK_GROUP;
    }

    /** @return list<string> every defined kind (drives the form dropdown and validation) */
    public static function allKinds(): array
    {
        return array_keys(self::GROUPS);
    }

    /**
     * @return list<string> every color group in definition order, always
     * including the fallback group (drives the legend)
     */
    public static function groups(): array
    {
        $groups = array_values(array_unique(self::GROUPS));
        if (!in_array(self::FALLBACK_GROUP, $groups, true)) {
            $groups[] = self::FALLBACK_GROUP;
        }
        return $groups;
    }

    /** @return list<string> every kind belonging to a group */
    public static function kindsForGroup(string $group): array
    {
        return array_keys(array_filter(self::GROUPS, fn (string $g): bool => $g === $group));
    }

    /** CSS class for a group; unknown groups resolve to the fallback class. */
    public static function classForGroup(string $group): string
    {
        return self::CLASSES[$group] ?? self::CLASSES[self::FALLBACK_GROUP];
    }

    /** CSS class for a kind (group-level color, fallback-safe). */
    public static function classFor(string $kind): string
    {
        return self::classForGroup(self::groupFor($kind));
    }
}
