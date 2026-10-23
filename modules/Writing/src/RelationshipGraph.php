<?php
declare(strict_types=1);

namespace Writing;

/**
 * Server-side renderer for the weighted circular relationship graph.
 * Pure function of its input: the same characters and relations always
 * produce an identical SVG (deterministic order, no time or randomness).
 *
 * Layout rules:
 * - Characters with level >= 7 form the protagonist zone in the center
 *   (small inner circle, sorted by level desc, then name).
 * - All other characters sit on the outer ring, evenly distributed,
 *   same deterministic sort order.
 * - Node radius scales with level: 8px at level 0 up to 24px at level 10.
 * - Nodes with an image render as circular clipped <image>, others as a
 *   plain circle. The label renders below the node as "Name · L<level>".
 * - Reciprocal pairs (A->B and B->A) merge into ONE outward-bowed Bezier
 *   curve; the A->B kind labels near B's end, the B->A kind near A's end.
 * - One-directional relations get an arrowhead pointing at the target.
 * - Edge colors come from the emotional stance (Stances::classFor); rows
 *   without a stance use the neutral class. Labels keep showing the
 *   structural role kind. Unknown stances fall back to a neutral stroke
 *   class; self-references are skipped defensively.
 */
final class RelationshipGraph
{
    private const PROTAGONIST_LEVEL = 7;
    private const WIDTH = 900;
    private const HEIGHT = 700;
    private const CENTER_X = 450.0;
    private const CENTER_Y = 350.0;
    private const OUTER_RADIUS = 250.0;
    private const INNER_RADIUS = 80.0;
    private const BOW_FACTOR = 0.22;


    /**
     * @param list<array{id: string, slug: string, title: string, level: int, has_image: bool}> $characters
     * @param list<array{from_id: string, to_id: string, kind: string, stance: ?string, description: string}> $relations
     */
    public function render(string $projectId, array $characters, array $relations): string
    {
        if ($characters === []) {
            return '';
        }
        $nodes = $this->layout($characters);
        [$edges, $reciprocals] = $this->prepareEdges($relations);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT
            . '" role="img" class="relationship-graph">' . "\n";
        $svg .= "<defs>\n";
        $svg .= '<marker id="graph-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" '
            . 'orient="auto-start-reverse"><path d="M 0 1 L 9 5 L 0 9 z" fill="#475569"/></marker>' . "\n";
        foreach ($nodes as $node) {
            if ($node['has_image']) {
                $svg .= '<clipPath id="graph-clip-' . e($node['id']) . '"><circle cx="' . $this->num($node['x'])
                    . '" cy="' . $this->num($node['y']) . '" r="' . $this->num($node['r']) . '"/></clipPath>' . "\n";
            }
        }
        $svg .= "</defs>\n";
        foreach ($reciprocals as $pair) {
            $svg .= $this->mergedEdge($nodes, $pair);
        }
        foreach ($edges as $edge) {
            $svg .= $this->singleEdge($nodes, $edge);
        }
        foreach ($nodes as $node) {
            $svg .= $this->node($projectId, $node);
        }
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Determines node positions. Deterministic order everywhere:
     * level descending, then name ascending.
     *
     * @param list<array{id: string, slug: string, title: string, level: int, has_image: bool}> $characters
     * @return array<string, array{id: string, slug: string, title: string, level: int, has_image: bool, x: float, y: float, r: float}>
     */
    private function layout(array $characters): array
    {
        $sorted = $characters;
        usort($sorted, fn (array $a, array $b): int => [$b['level'], $a['title']] <=> [$a['level'], $b['title']]);

        $protagonists = array_values(array_filter(
            $sorted,
            fn (array $c): bool => (int)$c['level'] >= self::PROTAGONIST_LEVEL
        ));
        $outer = array_values(array_filter(
            $sorted,
            fn (array $c): bool => (int)$c['level'] < self::PROTAGONIST_LEVEL
        ));

        $nodes = [];
        $groups = [
            ['members' => $protagonists, 'radius' => self::INNER_RADIUS],
            ['members' => $outer, 'radius' => self::OUTER_RADIUS],
        ];
        foreach ($groups as $group) {
            $count = count($group['members']);
            foreach ($group['members'] as $i => $character) {
                $angle = $count === 1
                    ? -M_PI / 2
                    : (2 * M_PI * $i / $count) - M_PI / 2;
                $x = self::CENTER_X + $group['radius'] * cos($angle);
                $y = self::CENTER_Y + $group['radius'] * sin($angle);
                $nodes[$character['id']] = [
                    'id' => (string)$character['id'],
                    'slug' => (string)$character['slug'],
                    'title' => (string)$character['title'],
                    'level' => (int)$character['level'],
                    'has_image' => (bool)$character['has_image'],
                    'x' => $x,
                    'y' => $y,
                    'r' => $this->radiusFor((int)$character['level']),
                ];
            }
        }
        return $nodes;
    }

    /** Node radius scales with the level: 8px at level 0 up to 24px at level 10. */
    private function radiusFor(int $level): float
    {
        return 8.0 + max(0, min(10, $level)) * 1.6;
    }

    /**
     * Splits relations into one-directional edges and reciprocal pairs.
     * Self-references are skipped defensively.
     *
     * @param list<array{from_id: string, to_id: string, kind: string, stance: ?string, description: string}> $relations
     * @return array{0: list<array{from_id: string, to_id: string, kind: string, stance: ?string, description: string}>,
     *     1: list<array{a: string, b: string, ab: array{kind: string, stance: ?string, description: string}, ba: array{kind: string, stance: ?string, description: string}}>}
     */
    private function prepareEdges(array $relations): array
    {
        $seen = [];
        $pairs = [];
        $singles = [];
        foreach ($relations as $relation) {
            $from = (string)$relation['from_id'];
            $to = (string)$relation['to_id'];
            if ($from === $to) {
                continue;
            }
            $key = $from < $to ? $from . '|' . $to : $to . '|' . $from;
            if (!isset($seen[$key])) {
                $seen[$key] = $relation;
                continue;
            }
            $first = $seen[$key];
            $pairs[] = [
                'a' => (string)$first['from_id'],
                'b' => (string)$first['to_id'],
                'ab' => $this->edgeData($first),
                'ba' => $this->edgeData($relation),
            ];
            unset($seen[$key]);
        }
        foreach ($seen as $relation) {
            $singles[] = $this->edgeData($relation);
        }
        return [$singles, $pairs];
    }

    /**
     * Normalizes a relation row into edge data; the stance is optional and
     * NULL renders as the neutral color group.
     *
     * @param array<string, mixed> $relation
     * @return array{from_id: string, to_id: string, kind: string, stance: ?string, description: string}
     */
    private function edgeData(array $relation): array
    {
        return [
            'from_id' => (string)$relation['from_id'],
            'to_id' => (string)$relation['to_id'],
            'kind' => (string)$relation['kind'],
            'stance' => isset($relation['stance']) && $relation['stance'] !== null && $relation['stance'] !== ''
                ? (string)$relation['stance']
                : null,
            'description' => (string)$relation['description'],
        ];
    }

    /**
     * @param array<string, array{id: string, slug: string, title: string, level: int, has_image: bool, x: float, y: float, r: float}> $nodes
     * @param array{a: string, b: string, ab: array{kind: string, stance: ?string, description: string}, ba: array{kind: string, stance: ?string, description: string}} $pair
     */
    private function mergedEdge(array $nodes, array $pair): string
    {
        $a = $nodes[$pair['a']] ?? null;
        $b = $nodes[$pair['b']] ?? null;
        if ($a === null || $b === null) {
            return '';
        }
        $midX = ($a['x'] + $b['x']) / 2;
        $midY = ($a['y'] + $b['y']) / 2;
        $dirX = $midX - self::CENTER_X;
        $dirY = $midY - self::CENTER_Y;
        $len = hypot($dirX, $dirY);
        $bow = hypot($b['x'] - $a['x'], $b['y'] - $a['y']) * self::BOW_FACTOR;
        $cx = $len === 0.0 ? $midX : $midX + $dirX / $len * $bow;
        $cy = $len === 0.0 ? $midY : $midY + $dirY / $len * $bow;

        [$x1, $y1] = $this->boundaryPoint($a, $cx, $cy);
        [$x2, $y2] = $this->boundaryPoint($b, $cx, $cy);

        $title = trim($this->tooltip($pair['ab']) . ' / ' . $this->tooltip($pair['ba']));
        $svg = '<path class="graph-edge ' . e($this->classFor($pair['ab']['stance'])) . '" d="M ' . $this->num($x1) . ' '
            . $this->num($y1) . ' Q ' . $this->num($cx) . ' ' . $this->num($cy) . ' ' . $this->num($x2) . ' '
            . $this->num($y2) . '" fill="none">';
        if ($title !== '') {
            $svg .= '<title>' . e($title) . '</title>';
        }
        $svg .= "</path>\n";

        [$tNearB, $tNearA] = $this->curvePoints($x1, $y1, $cx, $cy, $x2, $y2);
        $svg .= $this->edgeLabel($tNearB, $pair['ab']['kind'], $pair['ab']['stance'], $pair['ab']['description']);
        $svg .= $this->edgeLabel($tNearA, $pair['ba']['kind'], $pair['ba']['stance'], $pair['ba']['description']);
        return $svg;
    }

    /**
     * @param array<string, array{id: string, slug: string, title: string, level: int, has_image: bool, x: float, y: float, r: float}> $nodes
     * @param array{from_id: string, to_id: string, kind: string, stance: ?string, description: string} $edge
     */
    private function singleEdge(array $nodes, array $edge): string
    {
        $from = $nodes[$edge['from_id']] ?? null;
        $to = $nodes[$edge['to_id']] ?? null;
        if ($from === null || $to === null) {
            return '';
        }
        [$x1, $y1] = $this->boundaryPoint($from, $to['x'], $to['y']);
        [$x2, $y2] = $this->boundaryPoint($to, $from['x'], $from['y']);
        $svg = '<path class="graph-edge ' . e($this->classFor($edge['stance'])) . '" d="M ' . $this->num($x1) . ' '
            . $this->num($y1) . ' L ' . $this->num($x2) . ' ' . $this->num($y2)
            . '" fill="none" marker-end="url(#graph-arrow)">';
        $title = $this->tooltip($edge);
        if ($title !== '') {
            $svg .= '<title>' . e($title) . '</title>';
        }
        $svg .= "</path>\n";
        [$midX, $midY] = [($x1 + $x2) / 2, ($y1 + $y2) / 2];
        $svg .= $this->edgeLabel([$midX, $midY], $edge['kind'], $edge['stance'], $edge['description']);
        return $svg;
    }

    /**
     * @param array{id: string, slug: string, title: string, level: int, has_image: bool, x: float, y: float, r: float} $node
     * @return array{0: float, 1: float}
     */
    private function boundaryPoint(array $node, float $towardsX, float $towardsY): array
    {
        $dx = $towardsX - $node['x'];
        $dy = $towardsY - $node['y'];
        $len = hypot($dx, $dy);
        if ($len === 0.0) {
            return [$node['x'], $node['y']];
        }
        return [
            $node['x'] + $dx / $len * ($node['r'] + 2),
            $node['y'] + $dy / $len * ($node['r'] + 2),
        ];
    }

    /**
     * Points on the quadratic Bezier near both ends for the two labels
     * of a merged reciprocal edge.
     *
     * @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}
     */
    private function curvePoints(float $x1, float $y1, float $cx, float $cy, float $x2, float $y2): array
    {
        $point = fn (float $t): array => [
            (1 - $t) ** 2 * $x1 + 2 * (1 - $t) * $t * $cx + $t ** 2 * $x2,
            (1 - $t) ** 2 * $y1 + 2 * (1 - $t) * $t * $cy + $t ** 2 * $y2,
        ];
        return [$point(0.78), $point(0.22)];
    }

    /**
     * @param array{0: float, 1: float} $position
     * @param array{kind: string, stance: ?string, description: string} $edge
     */
    private function edgeLabel(array $position, string $kind, ?string $stance, string $description): string
    {
        $svg = '<text class="graph-edge-label ' . e($this->classFor($stance)) . '" x="' . $this->num($position[0])
            . '" y="' . $this->num($position[1]) . '" text-anchor="middle"';
        $label = e(t('writing', 'kind_' . $kind));
        if (trim($description) !== '') {
            $svg .= '><title>' . e($description) . '</title>' . $label . '</text>' . "\n";
        } else {
            $svg .= '>' . $label . "</text>\n";
        }
        return $svg;
    }

    /**
     * @param array{id: string, slug: string, title: string, level: int, has_image: bool, x: float, y: float, r: float} $node
     */
    private function node(string $projectId, array $node): string
    {
        $label = $node['title'] . ' · L' . (string)$node['level'];
        $labelY = $node['y'] + $node['r'] + 16;
        $href = '/writing/' . rawurlencode($projectId) . '/characters/' . rawurlencode($node['slug']);
        $svg = '<a class="graph-node" href="' . e($href) . '">';
        if ($node['has_image']) {
            $imageHref = '/writing/' . rawurlencode($projectId) . '/characters/' . rawurlencode($node['slug']) . '/image';
            $svg .= '<image x="' . $this->num($node['x'] - $node['r']) . '" y="' . $this->num($node['y'] - $node['r'])
                . '" width="' . $this->num(2 * $node['r']) . '" height="' . $this->num(2 * $node['r'])
                . '" preserveAspectRatio="xMidYMid slice" clip-path="url(#graph-clip-' . e($node['id'])
                . ')" href="' . e($imageHref) . '"/>';
        } else {
            $svg .= '<circle cx="' . $this->num($node['x']) . '" cy="' . $this->num($node['y']) . '" r="'
                . $this->num($node['r']) . '" class="graph-node-circle" data-level="' . (int)$node['level'] . '"/>';
        }
        $labelLen = mb_strlen($label) * 6.2 + 8;
        $svg .= '<rect class="graph-node-label-bg" x="' . $this->num($node['x'] - $labelLen / 2) . '" y="'
            . $this->num($labelY - 11) . '" width="' . $this->num($labelLen) . '" height="15" rx="3"/>'
            . "\n";
        $svg .= '<text class="graph-node-label" x="' . $this->num($node['x']) . '" y="' . $this->num($labelY)
            . '" text-anchor="middle">' . e($label) . "</text>\n";
        $svg .= "</a>\n";
        return $svg;
    }

    /**
     * Tooltip text for an edge: the description plus the translated stance
     * when present (raw lang key without an initialized Translator).
     *
     * @param array{kind: string, stance: ?string, description: string} $edge
     */
    private function tooltip(array $edge): string
    {
        $parts = [];
        if (trim($edge['description']) !== '') {
            $parts[] = trim($edge['description']);
        }
        if ($edge['stance'] !== null) {
            $parts[] = t('writing', 'stance_' . $edge['stance']);
        }
        return implode(' - ', $parts);
    }

    private function classFor(?string $stance): string
    {
        return Stances::classFor((string)$stance);
    }

    private function num(float $value): string
    {
        return number_format($value, 1, '.', '');
    }
}
