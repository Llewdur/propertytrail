<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InspectionContactRequest;
use App\Http\Requests\SigningLinkRequest;
use App\Models\Delivery;
use App\Repositories\InspectionRepository;
use App\Services\ContactService;
use App\Services\DeliveryService;
use App\Services\SigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class InspectionDeliveryController extends Controller
{
    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly SigningService $signing,
        private readonly DeliveryService $deliveries,
        private readonly ContactService $contacts,
    ) {}

    public function index(int $inspection): JsonResponse
    {
        if ($this->inspections->find($inspection) === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        $rows = Delivery::query()->where('inspection_id', $inspection)->orderByDesc('id')->get();

        return response()->json(['data' => $rows->map(static fn(Delivery $row): array => $row->payload())->all()]);
    }

    public function saveContact(InspectionContactRequest $request, int $inspection): JsonResponse
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        $contact = $this->contacts->save(
            $model,
            (string) $request->validated('party'),
            $request->validated('email'),
            $request->validated('phone'),
        );

        return response()->json([
            'data' => ['party' => $contact->party, 'email' => $contact->email, 'phone' => $contact->phone],
        ]);
    }

    public function sendSigningLink(SigningLinkRequest $request, int $inspection): JsonResponse
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        $party = (string) $request->validated('party');

        if (!$this->contacts->canReach($model, $party)) {
            return response()->json([
                'message' => 'The ' . $party . ' has no email address or enabled phone number on file.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $link = $this->signing->issueLink($model, $party);
            $sent = $this->deliveries->sendSigningLink($model, $party, url('/sign/' . $link['token']), Auth::id());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => array_map(static fn(Delivery $row): array => $row->payload(), $sent),
        ], Response::HTTP_CREATED);
    }
}
