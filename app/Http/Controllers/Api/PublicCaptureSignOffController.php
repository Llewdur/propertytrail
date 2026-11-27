<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureSignOffRequest;
use App\Models\CaptureLink;
use App\Services\CaptureLinkService;
use App\Services\CaptureSignOffContent;
use App\Services\CaptureSignOffCopy;
use App\Services\CaptureSignOffService;
use App\Support\CaptureDeclarations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** The tenant reviews what they captured, declares it is them and it is accurate, and signs. */
final class PublicCaptureSignOffController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly CaptureSignOffService $signOffs,
        private readonly CaptureSignOffCopy $copies,
        private readonly CaptureSignOffContent $contents,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $content = $this->contents->snapshot($link);
        $hash = $this->contents->hash($content);
        $latest = $this->signOffs->latest($link->lease_id, $link->phase);

        return $this->private([
            'data' => [
                'status' => $this->signOffs->status($latest, $hash),
                'content_hash' => $hash,
                'declaration_version' => CaptureDeclarations::VERSION,
                'declarations' => CaptureDeclarations::for($content['tenant_name']),
                'tenant_name' => $content['tenant_name'],
                'rooms' => $this->contents->summary($content['rooms']),
                'signed' => $latest === null
                    ? null
                    : [
                        'version' => $latest->version,
                        'reference' => $latest->reference(),
                        'signed_at' => $latest->signed_at->toIso8601String(),
                    ],
            ],
        ]);
    }

    public function store(CaptureSignOffRequest $request): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $selfie = $request->file('selfie');

        if (!$selfie instanceof UploadedFile) {
            return $this->refuse('Take a selfie to sign.');
        }

        $comment = $request->string('comment')->trim()->value();

        try {
            $signOff = $this->signOffs->sign(
                $link,
                [
                    'signer_name' => (string) $request->validated('signer_name'),
                    'signature' => (string) $request->validated('signature'),
                    'content_hash' => (string) $request->validated('content_hash'),
                    'declaration_version' => (string) $request->validated('declaration_version'),
                    'comment' => $comment === '' ? null : $comment,
                ],
                $selfie,
                $request->validated('selfie_taken_at'),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->private([
            'data' => [
                'version' => $signOff->version,
                'reference' => $signOff->reference(),
                'content_hash' => $signOff->content_hash,
                'signed_at' => $signOff->signed_at->toIso8601String(),
            ],
        ], Response::HTTP_CREATED);
    }

    /** The tenant's own copy of their latest sign-off. */
    public function copy(Request $request): Response
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $latest = $this->signOffs->latest($link->lease_id, $link->phase);

        if ($latest === null) {
            return response()->json(['message' => 'Nothing has been signed yet.'], Response::HTTP_NOT_FOUND);
        }

        return $this->copies->download($latest);
    }
}
