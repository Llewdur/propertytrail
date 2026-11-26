<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\Organisation;
use App\Support\InspectionItemDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InspectionTemplateService
{
    public function ensureDefaultsFor(Organisation $organisation): void
    {
        foreach (InspectionItemDefaults::roomTypes() as $roomType) {
            /** @var Builder<InspectionTemplate> $query */
            $query = InspectionTemplate::query()
                ->withoutGlobalScope('organisation')
                ->where('organisation_id', $organisation->getKey());

            $template = $query->firstOrCreate(['room_type' => $roomType], [
                'organisation_id' => $organisation->getKey(),
                'name' => Str::headline($roomType) . ' inspection checklist',
                'is_active' => true,
            ]);

            if (!$template->wasRecentlyCreated) {
                continue;
            }

            foreach (InspectionItemDefaults::forRoomType($roomType) as $index => $label) {
                InspectionTemplateItem::query()->create([
                    'organisation_id' => $organisation->getKey(),
                    'inspection_template_id' => $template->getKey(),
                    'label' => $label,
                    'sort_order' => $index,
                ]);
            }
        }
    }

    /**
     * @return Collection<int, InspectionTemplateItem>
     */
    public function itemsForRoomType(Organisation $organisation, string $roomType): Collection
    {
        $this->ensureDefaultsFor($organisation);

        /** @var Builder<InspectionTemplate> $query */
        $query = InspectionTemplate::query()
            ->withoutGlobalScope('organisation')
            ->where('organisation_id', $organisation->getKey())
            ->where('room_type', $roomType)
            ->where('is_active', true);

        $template = $query->first();

        if ($template === null) {
            return new Collection();
        }

        return $template->items;
    }

    /**
     * @param  list<array{label: string, requires_note?: bool, sort_order?: int}>  $items
     */
    public function saveItems(InspectionTemplate $template, array $items): InspectionTemplate
    {
        DB::transaction(static function () use ($template, $items): void {
            // One by one, so each removal reaches the audit log.
            $template->items()->get()->each->delete();

            foreach ($items as $index => $item) {
                $template
                    ->items()
                    ->create([
                        'organisation_id' => $template->organisation_id,
                        'label' => $item['label'],
                        'requires_note' => $item['requires_note'] ?? false,
                        'sort_order' => $item['sort_order'] ?? $index,
                    ]);
            }
        });

        return $template->refresh()->load('items');
    }
}
