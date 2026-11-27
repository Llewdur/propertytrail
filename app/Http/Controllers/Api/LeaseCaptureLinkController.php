<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureLinkRequest;
use App\Http\Requests\SendCaptureLinkRequest;
use App\Jobs\SendCaptureLinkJob;
use App\Models\CaptureLink;
use App\Models\Delivery;
use App\Models\Lease;
use App\Models\Organisation;
use App\Repositories\LeaseRepository;
use App\Services\CaptureLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Staff side of the tenant capture link for one lease. */
final class LeaseCaptureLinkController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly CaptureLinkService $links,
    ) {}

    public function show(int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound();
        }

        $link = $this->links->current($model);

        return response()->json([
            'data' => $link === null
                ? null
                : [...$this->payload($link), 'photo_count' => $this->links->photos($link)->count()],
        ]);
    }

    public function store(CaptureLinkRequest $request, int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound();
        }

        $issued = $this->links->issue($model, (string) $request->validated('phase'), $this->userId());

        return response()->json(['data' => [
            ...$this->payload($issued['link']),
            'url' => $issued['url'],
        ]], Response::HTTP_CREATED);
    }

    public function send(SendCaptureLinkRequest $request, int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound();
        }

        $channels = $this->channels($model, $request->validated('email'), $request->validated('phone'));

        if ($channels === []) {
            return response()->json([
                'message' => 'WhatsApp is not enabled for this organisation. Add an email address or copy the link.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $issued = $this->links->issue($model, (string) $request->validated('phase'), $this->userId());
        $message =
            'Please photograph your property using this private link: '
            . $issued['url']
            . ' (it expires on '
            . $issued['link']->expires_at->toDateString()
            . ').';
        $queued = [];

        foreach ($channels as $channel => $recipient) {
            try {
                SendCaptureLinkJob::dispatch($issued['link']->id, $channel, $recipient, $message);
                $queued[] = $channel;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // The link is returned even when a send fails, so staff can still copy it.
        return response()->json(['data' => [
            ...$this->payload($issued['link']),
            'url' => $issued['url'],
            'queued' => $queued,
        ]], Response::HTTP_CREATED);
    }

    public function destroy(int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound();
        }

        return response()->json(['data' => ['revoked' => $this->links->revoke($model, $this->userId())]]);
    }

    /** @return array<string, string> channel => recipient */
    private function channels(Lease $lease, mixed $email, mixed $phone): array
    {
        $channels = [];

        if (is_string($email) && $email !== '') {
            $channels[Delivery::CHANNEL_MAIL] = $email;
        }

        $whatsApp =
            (Organisation::query()->find($lease->organisation_id)?->settings['delivery']['whatsapp'] ?? false) === true;

        if (is_string($phone) && $phone !== '' && $whatsApp) {
            $channels[Delivery::CHANNEL_WHATSAPP] = $phone;
        }

        return $channels;
    }

    /** @return array{phase: string, expires_at: string, last_used_at: ?string} */
    private function payload(CaptureLink $link): array
    {
        return [
            'phase' => $link->phase,
            'expires_at' => $link->expires_at->toIso8601String(),
            'last_used_at' => $link->last_used_at?->toIso8601String(),
        ];
    }

    private function userId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Lease not found.'], Response::HTTP_NOT_FOUND);
    }
}
