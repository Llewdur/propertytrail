<?php

declare(strict_types=1);

namespace App\Providers;

use App\Adapters\FleetAccountingAdapter;
use App\Adapters\FleetPushAdapter;
use App\Adapters\PulseAdapter;
use App\Adapters\R2StorageAdapter;
use App\Adapters\ZekiniMailAdapter;
use App\Adapters\ZekiniWhatsAppAdapter;
use App\Contracts\Adapters\AccountingAdapterInterface;
use App\Contracts\Adapters\MailAdapterInterface;
use App\Contracts\Adapters\PulseAdapterInterface;
use App\Contracts\Adapters\PushAdapterInterface;
use App\Contracts\Adapters\StorageAdapterInterface;
use App\Contracts\Adapters\WhatsAppAdapterInterface;
use App\Factories\AreaRoomWriteFactory;
use App\Factories\EvidenceWriteFactory;
use App\Factories\InspectionWriteFactory;
use App\Factories\LeaseWriteFactory;
use App\Factories\OrganisationWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Factories\UserWriteFactory;
use App\Repositories\AreaRoomRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\EvidenceRepository;
use App\Repositories\InspectionRepository;
use App\Repositories\LeaseRepository;
use App\Repositories\OrganisationRepository;
use App\Repositories\PropertyRepository;
use App\Repositories\UserRepository;
use App\Services\AuthEventSubscriber;
use App\Services\InspectionTemplateService;
use App\Support\PulseRequestView;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Zekini\PulseSdk\PulseClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentOrganisation::class);

        // Package providers register first, so this replaces the SDK's own binding.
        $this->app->singleton(
            PulseClient::class,
            static fn(Application $app): PulseClient => new PulseClient(
                $app->make(HttpFactory::class),
                new PulseRequestView(),
            ),
        );

        $this->app->bind(StorageAdapterInterface::class, R2StorageAdapter::class);
        $this->app->bind(AccountingAdapterInterface::class, FleetAccountingAdapter::class);
        $this->app->bind(WhatsAppAdapterInterface::class, ZekiniWhatsAppAdapter::class);
        $this->app->bind(MailAdapterInterface::class, ZekiniMailAdapter::class);
        $this->app->bind(PulseAdapterInterface::class, PulseAdapter::class);
        $this->app->bind(PushAdapterInterface::class, FleetPushAdapter::class);

        $this->app->singleton(OrganisationRepository::class);
        $this->app->singleton(PropertyRepository::class);
        $this->app->singleton(\App\Repositories\SiteRepository::class);
        $this->app->singleton(\App\Factories\SiteWriteFactory::class);
        $this->app->singleton(AreaRoomRepository::class);
        $this->app->singleton(EvidenceRepository::class);
        $this->app->singleton(LeaseRepository::class);
        $this->app->singleton(AuditLogRepository::class);
        $this->app->singleton(UserRepository::class);
        $this->app->singleton(InspectionRepository::class);
        $this->app->singleton(InspectionTemplateService::class);

        $this->app->singleton(OrganisationWriteFactory::class);
        $this->app->singleton(PropertyWriteFactory::class);
        $this->app->singleton(AreaRoomWriteFactory::class);
        $this->app->singleton(EvidenceWriteFactory::class);
        $this->app->singleton(LeaseWriteFactory::class);
        $this->app->singleton(UserWriteFactory::class);
        $this->app->singleton(InspectionWriteFactory::class);
    }

    public function boot(): void
    {
        // Read from config, not env(): .env is not loaded once the config is cached.
        EncryptCookies::except([(string) config('auth-package.trusted_devices.cookie')]);

        Event::subscribe(AuthEventSubscriber::class);

        Socialite::extend('google', static function (): \Laravel\Socialite\Contracts\Provider {
            $config = config('services.google', []);

            return Socialite::buildProvider(\Laravel\Socialite\Two\GoogleProvider::class, $config);
        });
    }
}
