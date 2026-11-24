<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\EvidenceWriteFactory;
use App\Models\EvidenceRecord;
use App\Repositories\EvidenceRepository;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class EvidenceIngestionService
{
    public function __construct(
        private readonly StorageAdapterInterface $storage,
        private readonly EvidenceRepository $evidence,
        private readonly EvidenceWriteFactory $factory,
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /**
     * @param class-string $attachableType
     * @param array<string, mixed> $context
     */
    public function ingest(
        UploadedFile $file,
        string $attachableType,
        int $attachableId,
        ?string $label = null,
        array $context = [],
    ): EvidenceRecord {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Uploaded file cannot be read.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Uploaded file cannot be read.');
        }

        $hash = hash('sha256', $contents);

        $existing = $this->evidence->findByHash($hash);

        if ($existing !== null) {
            $this->factory->link($existing, $attachableType, $attachableId, $label, $context);

            return $existing;
        }

        $organisationId = $this->currentOrganisation->id() ?? -1;
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $path = sprintf(
            'staging/organisations/%d/evidence/%s/%s.%s',
            $organisationId,
            now()->format('Y/m'),
            $hash,
            $extension,
        );

        $this->storage->put($path, $contents);

        $metadata = $this->exifMetadata($file);

        $record = $this->factory->createRecord([
            'organisation_id' => $organisationId,
            'sha256_hash' => $hash,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size_bytes' => $file->getSize(),
            'storage_driver' => 'r2',
            'storage_path' => $path,
            'captured_at' => $metadata['captured_at'] ?? null,
            'gps_latitude' => $metadata['gps_latitude'] ?? null,
            'gps_longitude' => $metadata['gps_longitude'] ?? null,
            'camera_make' => $metadata['camera_make'] ?? null,
            'camera_model' => $metadata['camera_model'] ?? null,
            'uploaded_by_user_id' => Auth::id(),
        ]);

        $this->factory->link($record, $attachableType, $attachableId, $label, $context);

        return $record;
    }

    /** @return array<string, mixed> */
    private function exifMetadata(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false || !function_exists('exif_read_data')) {
            return [];
        }

        set_error_handler(static fn(): bool => true);

        try {
            $exif = exif_read_data($path);
        } finally {
            restore_error_handler();
        }

        if (!is_array($exif)) {
            return [];
        }

        return [
            'camera_make' => $exif['Make'] ?? null,
            'camera_model' => $exif['Model'] ?? null,
            'captured_at' => $this->normaliseDateTime($exif['DateTimeOriginal'] ?? null),
            'gps_latitude' => $this->gpsCoordinate($exif, 'GPSLatitude', $exif['GPSLatitudeRef'] ?? 'N'),
            'gps_longitude' => $this->gpsCoordinate($exif, 'GPSLongitude', $exif['GPSLongitudeRef'] ?? 'E'),
        ];
    }

    private function normaliseDateTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $value);

        return $parsed === false ? null : $parsed->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $exif */
    private function gpsCoordinate(array $exif, string $key, string $ref): ?float
    {
        $parts = $exif[$key] ?? null;

        if (!is_array($parts) || count($parts) < 3) {
            return null;
        }

        $degrees = $this->rationalToFloat($parts[0]);
        $minutes = $this->rationalToFloat($parts[1]);
        $seconds = $this->rationalToFloat($parts[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $value = $degrees + ($minutes / 60) + ($seconds / 3600);

        return in_array($ref, ['S', 'W'], true) ? -$value : $value;
    }

    private function rationalToFloat(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value) || !str_contains($value, '/')) {
            return null;
        }

        [$numerator, $denominator] = explode('/', $value, 2);
        $denominator = (float) $denominator;

        return $denominator === 0.0 ? null : (float) $numerator / $denominator;
    }
}
