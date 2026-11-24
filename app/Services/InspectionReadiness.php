<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;
use Illuminate\Database\Eloquent\Collection;

final class InspectionReadiness
{
    /**
     * @return list<string>
     */
    public function errors(Inspection $inspection): array
    {
        /** @var list<string> $errors */
        $errors = [];

        if ($inspection->sections->isEmpty()) {
            $errors[] = 'Add rooms to the property before submitting this inspection.';
        }

        $unsaved = $inspection->sections->filter(
            static fn(InspectionSection $section): bool => $section->saved_at === null,
        );

        foreach ($unsaved as $section) {
            $errors[] = "Save {$section->title} before submitting.";
        }

        $unrated = $inspection
            ->sections
            ->flatMap(static fn(InspectionSection $section): Collection => $section->items)
            ->filter(static fn(InspectionItem $item): bool => $item->condition === null)
            ->count();

        if ($unrated > 0) {
            $errors[] = "{$unrated} item(s) are not rated yet.";
        }

        return $errors;
    }
}
