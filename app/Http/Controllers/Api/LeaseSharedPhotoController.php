<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareConditionPhotoRequest;
use App\Models\SharedConditionPhoto;
use App\Repositories\LeaseRepository;
use App\Services\SharedConditionPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Staff choose which earlier condition photos the lease's tenant may see. */
final class LeaseSharedPhotoController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly SharedConditionPhotoService $shared,
    ) {}

    public function index(int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound('Lease not found.');
        }

        return response()->json([
            'data' => $this->shared
                ->forLease($model)
                ->map($this->payload(...))
                ->values()
                ->all(),
        ]);
    }

    public function store(ShareConditionPhotoRequest $request, int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound('Lease not found.');
        }

        if ($model->hasMovedOut()) {
            return response()->json(['message' => 'This tenant has moved out.'], Response::HTTP_CONFLICT);
        }

        $source = $this->shared->shareable($model, (int) $request->validated('evidence_link_id'));

        if ($source === null) {
            return $this->invalid('evidence_link_id', 'Choose a photo from an earlier tenancy of this property.');
        }

        $file = $request->file('file');
        $description = $request->string('description')->trim()->toString();
        $id = Auth::id();

        try {
            $photo = $this->shared->share(
                $model,
                $source,
                $file instanceof UploadedFile ? $file : null,
                $description === '' ? null : $description,
                $id === null ? null : (int) $id,
            );
        } catch (RuntimeException) {
            return $this->invalid('file', 'This photo cannot be shared as it is. Upload an edited copy instead.');
        }

        return response()->json(['data' => $this->payload($photo)], Response::HTTP_CREATED);
    }

    public function photo(int $lease, int $shared): Response
    {
        $photo = $this->shared->findLive($lease, $shared);
        $contents = $photo === null ? null : $this->shared->contents($photo);

        if ($photo === null || $contents === null) {
            return $this->notFound('Photo not found.');
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $photo->mime_type,
            'Content-Disposition' => 'attachment',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(int $lease, int $shared): JsonResponse
    {
        $photo = $this->shared->find($lease, $shared);

        if ($photo === null) {
            return $this->notFound('Photo not found.');
        }

        if ($photo->withdrawn_at === null) {
            $this->shared->withdraw($photo);
        }

        return response()->json(['data' => $this->payload($photo)]);
    }

    /** @return array<string, mixed> */
    private function payload(SharedConditionPhoto $photo): array
    {
        return $photo->staffView();
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_NOT_FOUND);
    }
}
