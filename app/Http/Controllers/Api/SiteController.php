<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\AreaRoomWriteFactory;
use App\Factories\SiteWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAreaRoomRequest;
use App\Http\Requests\StoreSiteRequest;
use App\Models\Property;
use App\Models\Site;
use App\Models\User;
use App\Repositories\AreaRoomRepository;
use App\Repositories\PropertyRepository;
use App\Repositories\SiteRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SiteController extends Controller
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteWriteFactory $factory,
        private readonly AreaRoomRepository $areas,
        private readonly AreaRoomWriteFactory $areaFactory,
        private readonly PropertyRepository $properties,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Property::class);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->sites->all($this->properties->visibleIdsFor($user))]);
    }

    public function store(StoreSiteRequest $request): JsonResponse
    {
        $this->authorize('manage', Site::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $site = $this->factory->create($data);

        return response()->json(['data' => $site->load(['areas', 'units'])], Response::HTTP_CREATED);
    }

    public function storeArea(StoreAreaRoomRequest $request, int $site): JsonResponse
    {
        $this->authorize('manage', Site::class);

        if ($this->sites->find($site) === null) {
            return response()->json(['message' => 'Site not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $area = $this->areaFactory->create(
            $data
            + [
                'site_id' => $site,
                'sort_order' => 0,
            ],
        );

        return response()->json(['data' => $area], Response::HTTP_CREATED);
    }

    public function destroyArea(int $site, int $area): JsonResponse
    {
        $this->authorize('manage', Site::class);

        $model = $this->areas->findSiteArea($site, $area);

        if ($model === null) {
            return response()->json(['message' => 'Area not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->areaFactory->delete($model);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
