<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Http\Controllers\Controller;
use App\Repositories\EvidenceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class EvidenceDownloadController extends Controller
{
    public function __construct(
        private readonly StorageAdapterInterface $storage,
        private readonly EvidenceRepository $evidence,
    ) {}

    public function __invoke(int $evidence): Response|JsonResponse
    {
        $record = $this->evidence->find($evidence);
        $contents = $record === null ? null : $this->storage->get($record->storage_path);

        if ($record === null || $contents === null) {
            return response()->json(['message' => 'Evidence file not found.'], HttpResponse::HTTP_NOT_FOUND);
        }

        return response($contents, HttpResponse::HTTP_OK, [
            'Content-Type' => $record->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $record->file_name,
                'evidence-' . $record->id,
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
