<?php
declare(strict_types=1);

namespace Writing;

/**
 * Single source of truth for the stance taxonomy (the emotional layer of a
 * relation) and its color groups. Every consumer (form dropdown, validation,
 * graph edge colors, legend) reads from this class - no duplicated stance
 * or group lists.
 *
 * A stance is directional: A->B may be 'love' while B->A is 'hatred'.
 * NULL/empty stance is valid and renders as the neutral group, so data
 * written before the stance existed never breaks.
 *
 * Extension recipe (see README):
 * - Add a stance: one line in STANCE_GROUPS + one lang line per language
 *   (stance_<key>); the parity test enforces completeness.
 * - Add a group: one STANCE_GROUPS entry + one CSS class with a stroke
 *   color in public/assets/css/master.css (reading the --rel-<group>-*
 *   variables) + stancegroup_<group> lang lines; the legend picks it up
 *   automatically.
 * - Guideline: max 7 groups total for legend readability; prefer fitting
 *   a new stance into an existing group over creating new ones.
 * - Unknown/legacy stance values never break rendering: groupFor() maps
 *   them to the neutral group.
 */
final class Stances
{
    /** Fine-grained stances mapped to their color group. */
    public const STANCE_GROUPS = [
        'love' => 'love',
        'passion' => 'love',
        'devotion' => 'love',
        'affection' => 'bond',
        'friendship' => 'bond',
        'loyalty' => 'bond',
        'protectiveness' => 'bond',
        'respect' => 'esteem',
        'gratitude' => 'esteem',
        'admiration' => 'esteem',
        'hatred' => 'hostility',
        'resentment' => 'hostility',
        'jealousy' => 'hostility',
        'contempt' => 'hostility',
        'rivalry' => 'rivalry',
        'competition' => 'rivalry',
        'fear' => 'fear',
        'awe' => 'fear',
        'indifference' => 'neutral',
        'complicated' => 'neutral',
    ];

    /** Color group => CSS class name (the actual colors live in the stylesheet). */
    public const CLASSES = [
        'love' => 'rel-love',
        'bond' => 'rel-bond',
        'esteem' => 'rel-esteem',
        'hostility' => 'rel-hostility',
        'rivalry' => 'rel-rivalry',
        'fear' => 'rel-fear',
        'neutral' => 'rel-neutral',
    ];

    public const FALLBACK_GROUP = 'neutral';

    /** Color group of a stance; unknown or empty stances resolve to the fallback group. */
    public static function groupFor(string $stance): string
    {
        return self::STANCE_GROUPS[$stance] ?? self::FALLBACK_GROUP;
    }

    /** @return list<string> every defined stance (drives the form dropdown and validation) */
    public static function allStances(): array
    {
        return array_keys(self::STANCE_GROUPS);
    }

    /**
     * @return list<string> every color group in definition order, always
     * including the fallback group (drives the legend)
     */
    public static function groups(): array
    {
        $groups = array_values(array_unique(self::STANCE_GROUPS));
        if (!in_array(self::FALLBACK_GROUP, $groups, true)) {
            $groups[] = self::FALLBACK_GROUP;
        }
        return $groups;
    }

    /** @return list<string> every stance belonging to a group */
    public static function stancesForGroup(string $group): array
    {
        return array_keys(array_filter(self::STANCE_GROUPS, fn (string $g): bool => $g === $group));
    }

    /** CSS class for a group; unknown groups resolve to the fallback class. */
    public static function classForGroup(string $group): string
    {
        return self::CLASSES[$group] ?? self::CLASSES[self::FALLBACK_GROUP];
    }

    /** CSS class for a stance (group-level color, fallback-safe). */
    public static function classFor(string $stance): string
    {
        return self::classForGroup(self::groupFor($stance));
    }
}
