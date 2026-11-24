<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class InspectionService
{
    /**
     * @param array<int, array<string, mixed>> $rows items payload, see SaveInspectionSectionRequest
     */
    public function saveSection(Inspection $inspection, InspectionSection $section, array $rows): void
    {
        DB::transaction(function () use ($inspection, $section, $rows): void {
            $locked = Inspection::query()->lockForUpdate()->find($inspection->id);

            if ($locked === null || !$locked->isDraft()) {
                throw new InvalidArgumentException(
                    'A submitted inspection can no longer be changed. Start a new inspection instead.',
                );
            }

            /** @var Collection<int, InspectionItem> $existing */
            $existing = $section->items->keyBy('id');

            foreach ($rows as $index => $row) {
                $this->saveItem($inspection, $section, $existing, $row, $index);
            }

            $section->saved_at = now();
            $section->save();
        });
    }

    /**
     * @return list<string> readiness errors; empty means the inspection was submitted
     */
    public function submit(Inspection $inspection, int $userId): array
    {
        return DB::transaction(static function () use ($inspection, $userId): array {
            $locked = Inspection::query()->lockForUpdate()->find($inspection->id);

            if ($locked === null || !$locked->isDraft()) {
                return ['This inspection has already been submitted.'];
            }

            $inspection->refresh()->load('sections.items');
            $errors = new InspectionReadiness()->errors($inspection);

            if ($errors !== []) {
                return $errors;
            }

            $inspection->status = Inspection::STATUS_SUBMITTED;
            $inspection->submitted_at = now();
            $inspection->performed_by_user_id = $userId;
            $inspection->save();

            return [];
        });
    }

    /**
     * @param Collection<int, InspectionItem> $existing
     * @param array<string, mixed> $row
     */
    private function saveItem(
        Inspection $inspection,
        InspectionSection $section,
        Collection $existing,
        array $row,
        int $index,
    ): void {
        $item = $this->resolveItem($inspection, $section, $existing, $row['id'] ?? null);

        $item->label = (string) $row['label'];
        $item->condition = array_key_exists('condition', $row)
            ? self::nullableString($row['condition'])
            : $item->condition;
        $item->note = array_key_exists('note', $row) ? self::nullableString($row['note']) : $item->note;
        $item->sort_order = (int) ($row['sort_order'] ?? $index);
        $item->save();
    }

    /**
     * @param Collection<int, InspectionItem> $existing
     */
    private function resolveItem(
        Inspection $inspection,
        InspectionSection $section,
        Collection $existing,
        mixed $id,
    ): InspectionItem {
        if ($id === null) {
            return new InspectionItem([
                'organisation_id' => $inspection->organisation_id,
                'inspection_section_id' => $section->id,
                'source' => InspectionItem::SOURCE_MANUAL,
            ]);
        }

        return (
            $existing->get((int) $id) ?? throw new InvalidArgumentException(
                'Inspection item does not belong to this room.',
            )
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
