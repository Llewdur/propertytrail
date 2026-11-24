<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant-isolation boundary itself. Every query against a model using this
 * trait is filtered to the current organisation and every create auto-assigns
 * it. With no organisation resolved the scope matches nothing, never
 * everything. Cross-organisation access must be requested explicitly through
 * allOrganisations() or forOrganisation(), so a leak cannot happen by default.
 *
 * @mixin Model
 */
trait OrganisationScoped
{
    public static function bootOrganisationScoped(): void
    {
        static::addGlobalScope('organisation', static function (Builder $query): void {
            $organisationId = app(CurrentOrganisation::class)->id();

            $query->where($query->getModel()->qualifyColumn('organisation_id'), $organisationId ?? -1);
        });

        static::creating(static function (Model $model): void {
            if ($model->organisation_id === null) {
                $model->organisation_id = app(CurrentOrganisation::class)->id();
            }
        });
    }

    public function scopeAllOrganisations(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organisation');
    }

    public function scopeForOrganisation(Builder $query, int $organisationId): Builder
    {
        return $query->withoutGlobalScope('organisation')->where(
            $query->getModel()->qualifyColumn('organisation_id'),
            $organisationId,
        );
    }
}
