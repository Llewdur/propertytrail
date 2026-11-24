<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePilotEnquiryRequest;
use App\Models\PilotEnquiry;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PilotEnquiryController extends Controller
{
    public function store(StorePilotEnquiryRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json(['data' => PilotEnquiry::query()->create($data)], Response::HTTP_CREATED);
    }
}
