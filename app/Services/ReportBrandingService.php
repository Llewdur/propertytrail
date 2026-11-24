<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\Organisation;
use App\Support\OrganisationLogo;
use App\Support\ReportPlan;
use Illuminate\Http\UploadedFile;

final class ReportBrandingService
{
    public function __construct(
        private readonly StorageAdapterInterface $storage,
    ) {}

    /** @return array{watermark: ?string, logo_data_uri: ?string} */
    public function forOrganisation(?Organisation $organisation): array
    {
        $rules = ReportPlan::rulesFor($organisation);

        return [
            'watermark' => $rules['watermark'] ? (string) config('reports.watermark_text') : null,
            'logo_data_uri' => $rules['custom_logo'] ? $this->logoDataUri($organisation) : null,
        ];
    }

    public function storeLogo(Organisation $organisation, UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();
        $path = OrganisationLogo::pathFor($organisation, $mime);

        $previous = $organisation->settings['branding']['logo_path'] ?? null;

        $this->storage->put($path, (string) file_get_contents($file->getRealPath()));

        $settings = $organisation->settings ?? [];
        $settings['branding'] = ['logo_path' => $path, 'logo_mime' => $mime];
        $organisation->forceFill(['settings' => $settings])->save();

        if (is_string($previous) && $previous !== $path) {
            $this->storage->delete($previous);
        }
    }

    private function logoDataUri(?Organisation $organisation): ?string
    {
        $branding = $organisation?->settings['branding'] ?? null;
        $path = is_array($branding) ? $branding['logo_path'] ?? null : null;
        $mime = is_array($branding) ? $branding['logo_mime'] ?? null : null;

        if ($organisation === null || !OrganisationLogo::isOwn($organisation, $path, $mime)) {
            return null;
        }

        $contents = $this->storage->get((string) $path);

        return $contents === null ? null : 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
