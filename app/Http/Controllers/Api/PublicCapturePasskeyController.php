<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\CapturePasskeyRequest;
use App\Models\CaptureLink;
use App\Services\CaptureLinkService;
use App\Services\CapturePasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant's optional phone unlock (passkey) for a capture link: set one up after
 * the selfie, then unlock with it on a later visit before photos are sent.
 */
final class PublicCapturePasskeyController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly CapturePasskeyService $passkeys,
    ) {}

    public function registrationOptions(Request $request): JsonResponse
    {
        // Setting one up needs this visit's access, so an unlocked visit cannot be replaced by a new phone.
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $options = $this->passkeys->registrationOptions($link);

        if ($options === null) {
            return $this->refuse('A passkey is already set up for this link.');
        }

        return $this->private(['data' => ['options' => $options]]);
    }

    public function register(CapturePasskeyRequest $request): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        try {
            $unlock = $this->passkeys->register($link, $request->credential());
        } catch (InvalidPasskeyException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->private(['data' => ['unlock' => $unlock]], Response::HTTP_CREATED);
    }

    public function verificationOptions(Request $request): JsonResponse
    {
        $link = $this->link($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $options = $this->passkeys->verificationOptions($link);

        if ($options === null) {
            return $this->refuse('No passkey is set up for this link.');
        }

        return $this->private(['data' => ['options' => $options]]);
    }

    public function verify(CapturePasskeyRequest $request): JsonResponse
    {
        $link = $this->link($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        try {
            $unlock = $this->passkeys->verify($link, $request->credential());
        } catch (InvalidPasskeyException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->private(['data' => ['unlock' => $unlock]]);
    }
}
