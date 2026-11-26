<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceLink;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionReport;
use App\Models\InspectionSection;
use App\Support\ConditionVocabulary;

/**
 * Compares an outgoing inspection with the incoming baseline of the same lease.
 * Rows are categorised for the shared record only; liability is never decided.
 */
final class ComparisonService
{
    public const UNCHANGED = 'unchanged';
    public const EXISTING_DEFECT = 'existing_defect';
    public const WEAR_AND_TEAR = 'normal_wear_and_tear';
    public const POTENTIAL_NEW_DAMAGE = 'potential_new_damage';
    public const IMPROVED = 'improved';
    public const NOT_AT_MOVE_IN = 'not_recorded_at_move_in';
    public const NOT_AT_MOVE_OUT = 'not_inspected_at_move_out';
    public const NOT_ASSESSED = 'not_assessed';

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        self::UNCHANGED => 'No change',
        self::EXISTING_DEFECT => 'Existing defect (as at move-in)',
        self::WEAR_AND_TEAR => 'Normal wear and tear',
        self::POTENTIAL_NEW_DAMAGE => 'Potential new damage (new this tenancy)',
        self::IMPROVED => 'Improved since move-in',
        self::NOT_AT_MOVE_IN => 'Not rated at move-in',
        self::NOT_AT_MOVE_OUT => 'Not inspected at move-out',
        self::NOT_ASSESSED => 'Not rated at move-out',
    ];

    /** @var list<string> */
    private const DEFECT_CONDITIONS = [ConditionVocabulary::POOR, ConditionVocabulary::DAMAGED];

    public function baselineFor(Inspection $outgoing): ?Inspection
    {
        if ($outgoing->phase !== Inspection::PHASE_OUTGOING || $outgoing->lease_id === null) {
            return null;
        }

        return Inspection::query()
            ->where('lease_id', $outgoing->lease_id)
            ->where('phase', Inspection::PHASE_INCOMING)
            ->where('status', Inspection::STATUS_SUBMITTED)
            ->where('submitted_at', '<=', $outgoing->submitted_at ?? now())
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{
     *     baseline_id: int,
     *     baseline_reference: ?string,
     *     rows: list<array{
     *         room: string, item: string, incoming: ?string, outgoing: ?string,
     *         category: string, label: string, evidence_gap: bool,
     *         incoming_item_id: ?int, outgoing_item_id: ?int
     *     }>,
     *     summary: array<string, int>,
     *     evidence_gaps: int
     * }|null
     */
    public function compare(Inspection $outgoing): ?array
    {
        $baseline = $this->baselineFor($outgoing);

        if ($baseline === null) {
            return null;
        }

        $baseline->loadMissing('sections.items');
        $outgoing->loadMissing('sections.items');

        $rows = [];
        $usedItems = [];
        $pairs = $this->pairSections($baseline, $outgoing);

        foreach ($outgoing->sections as $section) {
            $incomingSection = $pairs[$section->id] ?? null;

            foreach ($section->items as $item) {
                $before = $this->matchingItem($incomingSection, $item, $usedItems);

                if ($before !== null) {
                    $usedItems[] = $before->id;
                }

                $rows[] = $this->row($section->title, $before, $item);
            }
        }

        foreach ($baseline->sections as $incomingSection) {
            foreach ($incomingSection->items as $before) {
                if (in_array($before->id, $usedItems, true)) {
                    continue;
                }

                $rows[] = $this->row($incomingSection->title, $before, null);
            }
        }

        $photographed = $this->photographedItemIds(array_values(array_map(
            static fn(mixed $id): int => (int) $id,
            array_filter(array_column($rows, 'incoming_item_id')),
        )));
        $summary = array_fill_keys(array_keys(self::CATEGORY_LABELS), 0);
        $gaps = 0;

        foreach ($rows as &$row) {
            $summary[$row['category']]++;
            $row['evidence_gap'] =
                in_array($row['outgoing'], self::DEFECT_CONDITIONS, true)
                && ($row['incoming_item_id'] === null || !array_key_exists($row['incoming_item_id'], $photographed));
            $gaps += $row['evidence_gap'] ? 1 : 0;
        }
        unset($row);

        return [
            'baseline_id' => $baseline->id,
            'baseline_reference' => InspectionReport::query()
                ->where('inspection_id', $baseline->id)
                ->orderByDesc('version')
                ->value('reference'),
            'rows' => $rows,
            'summary' => $summary,
            'evidence_gaps' => $gaps,
        ];
    }

    /**
     * Report annotations keyed by move-out item id.
     *
     * @param array{rows: list<array{category: string, outgoing_item_id: ?int, ...}>, ...}|null $comparison
     * @return array<int, ?string>
     */
    public function markers(?array $comparison): array
    {
        $markers = [];

        foreach ($comparison['rows'] ?? [] as $row) {
            if ($row['outgoing_item_id'] === null) {
                continue;
            }

            $markers[$row['outgoing_item_id']] = match ($row['category']) {
                self::EXISTING_DEFECT => '(as at move-in)',
                self::POTENTIAL_NEW_DAMAGE => '(potential new damage)',
                default => null,
            };
        }

        return $markers;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, true>
     */
    private function photographedItemIds(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return EvidenceLink::query()
            ->where('attachable_type', new InspectionItem()->getMorphClass())
            ->whereIn('attachable_id', $itemIds)
            ->pluck('attachable_id')
            ->mapWithKeys(static fn(mixed $id): array => [(int) $id => true])
            ->all();
    }

    /** @return array{room: string, item: string, incoming: ?string, outgoing: ?string, category: string, label: string, evidence_gap: bool, incoming_item_id: ?int, outgoing_item_id: ?int} */
    private function row(string $room, ?InspectionItem $before, ?InspectionItem $after): array
    {
        $category = $after === null
            ? self::NOT_AT_MOVE_OUT
            : $this->categorise($before?->condition, $after->condition, $before !== null);

        return [
            'room' => $room,
            'item' => $after->label ?? $before->label ?? '',
            'incoming' => $before?->condition,
            'outgoing' => $after?->condition,
            'category' => $category,
            'label' => self::CATEGORY_LABELS[$category],
            'evidence_gap' => false,
            'incoming_item_id' => $before?->id,
            'outgoing_item_id' => $after?->id,
        ];
    }

    public function categorise(?string $incoming, ?string $outgoing, bool $existedAtMoveIn): string
    {
        $after = $outgoing === null ? null : $this->rank($outgoing);

        if ($after === null) {
            return self::NOT_ASSESSED;
        }

        $before = $existedAtMoveIn && $incoming !== null ? $this->rank($incoming) : null;

        return match (true) {
            $before === null => self::NOT_AT_MOVE_IN,
            $after < $before => self::IMPROVED,
            $after === $before => $before >= (int) $this->rank(ConditionVocabulary::FAIR)
                ? self::EXISTING_DEFECT
                : self::UNCHANGED,
            in_array($outgoing, self::DEFECT_CONDITIONS, true) => self::POTENTIAL_NEW_DAMAGE,
            default => self::WEAR_AND_TEAR,
        };
    }

    private function rank(string $condition): ?int
    {
        $position = array_search($condition, ConditionVocabulary::keys(), true);

        return $position === false ? null : (int) $position;
    }

    /** @param list<int> $usedItems */
    private function matchingItem(?InspectionSection $incoming, InspectionItem $item, array $usedItems): ?InspectionItem
    {
        foreach ($incoming->items ?? [] as $candidate) {
            if (
                !in_array($candidate->id, $usedItems, true)
                && mb_strtolower(trim($candidate->label)) === mb_strtolower(trim($item->label))
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pairs every same-room match first, so a title match can never take a
     * baseline section that belongs to another outgoing room.
     *
     * @return array<int, InspectionSection> baseline sections keyed by move-out section id
     */
    private function pairSections(Inspection $baseline, Inspection $outgoing): array
    {
        $matchers = [
            static fn(InspectionSection $before, InspectionSection $after): bool => (
                $after->area_room_id !== null
                && $before->area_room_id === $after->area_room_id
            ),
            static fn(InspectionSection $before, InspectionSection $after): bool => (
                mb_strtolower(trim($before->title)) === mb_strtolower(trim($after->title))
            ),
        ];
        $pairs = [];
        $used = [];

        foreach ($matchers as $matches) {
            foreach ($outgoing->sections as $after) {
                if (array_key_exists($after->id, $pairs)) {
                    continue;
                }

                foreach ($baseline->sections as $before) {
                    if (array_key_exists($before->id, $used) || !$matches($before, $after)) {
                        continue;
                    }

                    $pairs[$after->id] = $before;
                    $used[$before->id] = true;

                    break;
                }
            }
        }

        return $pairs;
    }
}
