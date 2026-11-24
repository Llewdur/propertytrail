<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $property_id
 * @property int|null $lease_id
 * @property string $phase
 * @property string $status
 * @property string|null $overall_notes
 * @property int|null $performed_by_user_id
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property-read Collection<int, InspectionSection> $sections
 */
final class Inspection extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';

    public const PHASE_INCOMING = 'incoming';
    public const PHASE_OUTGOING = 'outgoing';

    /** @var list<string> */
    public const PHASES = ['incoming', 'outgoing', 'interim'];

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'property_id',
        'lease_id',
        'phase',
        'status',
        'overall_notes',
        'performed_by_user_id',
        'submitted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return HasMany<InspectionSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(InspectionSection::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<InspectionSignature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(InspectionSignature::class)->orderBy('signed_at');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * @param array<array-key, int> $itemIds
     * @return array<array-key, list<array<string, mixed>>>
     */
    private function itemPhotos(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $links = EvidenceLink::query()
            ->with('evidenceRecord')
            ->where('attachable_type', new InspectionItem()->getMorphClass())
            ->whereIn('attachable_id', $itemIds)
            ->orderBy('id')
            ->get();

        $replaced = $links->pluck('replaces_link_id')->filter()->all();
        $grouped = [];

        foreach ($links as $link) {
            $grouped[$link->attachable_id][] = [
                'link_id' => $link->id,
                'evidence_id' => $link->evidenceRecord->id,
                'caption' => $link->label,
                'file_name' => $link->evidenceRecord->file_name,
                'mime_type' => $link->evidenceRecord->mime_type,
                'taken_at' => $link->taken_at?->toIso8601String(),
                'replaces_link_id' => $link->replaces_link_id,
                'superseded' => in_array($link->id, $replaced, true),
            ];
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    public function payload(bool $withSignatureImages = false): array
    {
        $this->loadMissing(['sections.items', 'property:id,name', 'lease:id,lease_reference', 'signatures']);

        /** @var Collection<int, InspectionItem> $items */
        $items = $this->sections->flatMap(static fn(InspectionSection $section): Collection => $section->items);

        $photos = $this->itemPhotos(
            $items
                ->pluck('id')
                ->map(static fn(mixed $id): int => (int) $id)
                ->all(),
        );

        foreach ($items as $item) {
            $item->setRelation('photos', new Collection($photos[$item->id] ?? []));
        }

        $signatures = $this->signatures->map(
            static fn(InspectionSignature $signature): array => $signature->payload($withSignatureImages),
        )->all();

        return (
            [...$this->toArray(), 'signatures' => $signatures]
            + [
                'progress' => [
                    'sections_total' => $this->sections->count(),
                    'sections_saved' => $this->sections
                        ->filter(static fn(InspectionSection $section): bool => $section->saved_at !== null)
                        ->count(),
                    'items_total' => $items->count(),
                    'items_rated' => $items
                        ->filter(static fn(InspectionItem $item): bool => $item->condition !== null)
                        ->count(),
                ],
            ]
        );
    }
}
