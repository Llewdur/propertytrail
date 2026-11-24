<?php

declare(strict_types=1);

namespace App\Providers;

use App\Adapters\FleetAccountingAdapter;
use App\Adapters\PulseAdapter;
use App\Adapters\R2StorageAdapter;
use App\Adapters\ZekiniMailAdapter;
use App\Adapters\ZekiniWhatsAppAdapter;
use App\Contracts\Adapters\AccountingAdapterInterface;
use App\Contracts\Adapters\MailAdapterInterface;
use App\Contracts\Adapters\PulseAdapterInterface;
use App\Contracts\Adapters\StorageAdapterInterface;
use App\Contracts\Adapters\WhatsAppAdapterInterface;
use App\Factories\AreaRoomWriteFactory;
use App\Factories\EvidenceWriteFactory;
use App\Factories\LeaseWriteFactory;
use App\Factories\OrganisationWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Factories\UserWriteFactory;
use App\Repositories\AreaRoomRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\EvidenceRepository;
use App\Repositories\LeaseRepository;
use App\Repositories\OrganisationRepository;
use App\Repositories\PropertyRepository;
use App\Repositories\UserRepository;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentOrganisation::class);

        $this->app->bind(StorageAdapterInterface::class, R2StorageAdapter::class);
        $this->app->bind(AccountingAdapterInterface::class, FleetAccountingAdapter::class);
        $this->app->bind(WhatsAppAdapterInterface::class, ZekiniWhatsAppAdapter::class);
        $this->app->bind(MailAdapterInterface::class, ZekiniMailAdapter::class);
        $this->app->bind(PulseAdapterInterface::class, PulseAdapter::class);

        $this->app->singleton(OrganisationRepository::class);
        $this->app->singleton(PropertyRepository::class);
        $this->app->singleton(AreaRoomRepository::class);
        $this->app->singleton(EvidenceRepository::class);
        $this->app->singleton(LeaseRepository::class);
        $this->app->singleton(AuditLogRepository::class);
        $this->app->singleton(UserRepository::class);

        $this->app->singleton(OrganisationWriteFactory::class);
        $this->app->singleton(PropertyWriteFactory::class);
        $this->app->singleton(AreaRoomWriteFactory::class);
        $this->app->singleton(EvidenceWriteFactory::class);
        $this->app->singleton(LeaseWriteFactory::class);
        $this->app->singleton(UserWriteFactory::class);
    }

    public function boot(): void
    {
        Socialite::extend('google', static function (): \Laravel\Socialite\Contracts\Provider {
            $config = config('services.google', []);

            return Socialite::buildProvider(\Laravel\Socialite\Two\GoogleProvider::class, $config);
        });
    }
}
