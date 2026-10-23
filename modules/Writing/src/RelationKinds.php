<?php
declare(strict_types=1);

namespace Writing;

/**
 * Single source of truth for the structural role taxonomy of a relation.
 * Roles describe the structural relation (aunt, boss, mentor); the feeling
 * lives in the separate stance dimension (see Stances). Every consumer
 * (form dropdown, validation, graph edge labels) reads from this class.
 *
 * Roles are labels, not colors: the graph derives its edge colors from the
 * stance (Stances::classFor). The color helpers below remain for backward
 * compatibility but must not drive graph rendering anymore.
 *
 * Extension recipe (see README):
 * - Add a role: one line in GROUPS + one lang line per language
 *   (kind_<key>); the parity test enforces completeness.
 * - Unknown/legacy role values never break rendering: groupFor() maps
 *   them to the neutral 'other' group.
 */
final class RelationKinds
{
    /** Structural role kinds mapped to their role group. */
    public const GROUPS = [
        'kin' => 'family',
        'parent' => 'family',
        'child' => 'family',
        'grandparent' => 'family',
        'grandchild' => 'family',
        'sibling' => 'family',
        'cousin' => 'family',
        'spouse' => 'family',
        'engaged' => 'family',
        'heir' => 'family',
        'guardian' => 'family',
        'in_law' => 'family',
        'friend' => 'alliance',
        'ally' => 'alliance',
        'companion' => 'alliance',
        'teammate' => 'alliance',
        'neighbor' => 'alliance',
        'employer' => 'work',
        'employee' => 'work',
        'colleague' => 'work',
        'business_partner' => 'work',
        'mentor' => 'mentorship',
        'apprentice' => 'mentorship',
        'related' => 'other',
        'acquaintance' => 'other',
        'doctor' => 'other',
    ];

    /** Role group => CSS class name (legacy; kept for backward compatibility). */
    public const CLASSES = [
        'family' => 'rel-family',
        'alliance' => 'rel-alliance',
        'work' => 'rel-alliance',
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
