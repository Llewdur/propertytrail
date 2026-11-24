<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organisation;
use App\Services\InspectionTemplateService;
use Illuminate\Database\Seeder;

final class InspectionTemplateSeeder extends Seeder
{
    public function __construct(
        private readonly InspectionTemplateService $templates,
    ) {}

    public function run(): void
    {
        foreach (Organisation::query()->get() as $organisation) {
            $this->templates->ensureDefaultsFor($organisation);
        }
    }
}
