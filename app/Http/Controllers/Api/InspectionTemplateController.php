<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateInspectionTemplateRequest;
use App\Models\InspectionTemplate;
use App\Models\Organisation;
use App\Services\InspectionTemplateService;
use App\Support\ConditionVocabulary;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class InspectionTemplateController extends Controller
{
    public function __construct(
        private readonly InspectionTemplateService $templates,
    ) {}

    public function index(): JsonResponse
    {
        $organisation = $this->resolvedOrganisation();
        $this->templates->ensureDefaultsFor($organisation);

        $templates = $this->queryFor($organisation)->with('items')->orderBy('room_type')->get();

        return response()->json([
            'data' => $templates,
            'condition_scale' => ConditionVocabulary::all(),
        ]);
    }

    public function show(int $template): JsonResponse
    {
        $organisation = $this->resolvedOrganisation();

        $model = $this->queryFor($organisation)->with('items')->find($template);

        if ($model === null) {
            return response()->json(['message' => 'Template not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $model]);
    }

    public function update(UpdateInspectionTemplateRequest $request, int $template): JsonResponse
    {
        $organisation = $this->resolvedOrganisation();
        $this->authorize('manageTemplates', $organisation);

        $model = $this->queryFor($organisation)->find($template);

        if ($model === null) {
            return response()->json(['message' => 'Template not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array{items: list<array{label: string, requires_note?: bool, sort_order?: int}>} $data */
        $data = $request->validated();

        return response()->json(['data' => $this->templates->saveItems($model, $data['items'])]);
    }

    /** @return Builder<InspectionTemplate> */
    private function queryFor(Organisation $organisation): Builder
    {
        return InspectionTemplate::query()
            ->withoutGlobalScope('organisation')
            ->where('organisation_id', $organisation->getKey());
    }

    private function resolvedOrganisation(): Organisation
    {
        $organisationId = app(CurrentOrganisation::class)->id();

        $model = $organisationId === null ? null : Organisation::query()->find($organisationId);

        if ($model === null) {
            abort(Response::HTTP_NOT_FOUND, 'Organisation not found.');
        }

        return $model;
    }
}
