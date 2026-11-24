<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EvidenceLink;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;

final class ItemPhotoGuard
{
    /** Returns a refusal message, or null when the photo may be attached. */
    public function refusal(int $itemId, mixed $replacesLinkId): ?string
    {
        $item = InspectionItem::query()->findOrFail($itemId);
        $section = InspectionSection::query()->findOrFail($item->inspection_section_id);
        $inspection = Inspection::query()->findOrFail($section->inspection_id);

        if (!$inspection->isDraft()) {
            return 'A submitted inspection can no longer be changed.';
        }

        if ($replacesLinkId === null) {
            return null;
        }

        $original = EvidenceLink::query()->find((int) $replacesLinkId);
        $belongs =
            $original !== null
            && $original->attachable_type === $item->getMorphClass()
            && (int) $original->attachable_id === $itemId;

        if (!$belongs) {
            return 'The photo being replaced does not belong to this item.';
        }

        $alreadyReplaced = EvidenceLink::query()
            ->where('replaces_link_id', (int) $replacesLinkId)
            ->exists();

        return $alreadyReplaced ? 'That photo has already been replaced.' : null;
    }
}
