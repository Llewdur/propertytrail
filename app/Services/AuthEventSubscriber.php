<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's auth events, plus the ones the auth package raises no event for, read from its routes' responses.
 * Kept out of app/Listeners so that event discovery does not register it a second time.
 */
final class AuthEventSubscriber
{
    public function __construct(
        private readonly AuthEventRecorder $recorder,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->login(...));
        $events->listen(Logout::class, $this->logout(...));
        $events->listen(Failed::class, $this->failed(...));
        $events->listen(Lockout::class, $this->lockout(...));
        $events->listen(PasswordResetLinkSent::class, $this->passwordResetRequested(...));
        $events->listen(PasswordReset::class, $this->passwordResetDone(...));
        $events->listen(RequestHandled::class, $this->handled(...));
    }

    public function login(Login $event): void
    {
        if (!$this->awaitsSecondFactor($event->user)) {
            $this->recorder->record('login_ok', 'ok', $event->user->getAuthIdentifier());
        }
    }

    public function logout(Logout $event): void
    {
        if ($event->user !== null && !$this->awaitsSecondFactor($event->user)) {
            $this->recorder->record('logout', 'ok', $event->user->getAuthIdentifier());
        }
    }

    public function failed(Failed $event): void
    {
        // Never the attempted email address.
        $this->recorder->record('login_failed', 'failed', $event->user?->getAuthIdentifier());
    }

    public function lockout(Lockout $event): void
    {
        $this->recorder->record('lockout', 'blocked');
    }

    public function passwordResetRequested(PasswordResetLinkSent $event): void
    {
        $this->recorder->record('password_reset_requested', 'ok', $event->user->getAuthIdentifier());
    }

    public function passwordResetDone(PasswordReset $event): void
    {
        $this->recorder->record('password_reset_done', 'ok', $event->user->getAuthIdentifier());
    }

    public function handled(RequestHandled $event): void
    {
        $request = $event->request;
        $status = $event->response->getStatusCode();
        $prefix = trim((string) config('auth-package.prefix'), '/') . '/';

        if (!$request->isMethod('POST') || !$request->is($prefix . '*')) {
            return;
        }

        $userId = $request->user((string) config('auth-package.guard'))?->getAuthIdentifier();

        match (true) {
            $request->is($prefix . 'register') && $status === Response::HTTP_CREATED => $this->recorder->record(
                'sign_up',
                'ok',
                $userId,
            ),
            $request->is($prefix . 'api-tokens') && $status === Response::HTTP_CREATED => $this->recorder->record(
                'api_key_created',
                'ok',
                $request->user()?->getAuthIdentifier(),
            ),
            $request->is($prefix . 'two-factor/challenge') && $status === Response::HTTP_OK => $this->recorder->record(
                'mfa_pass',
                'ok',
                $userId,
            ),
            $request->is($prefix . 'two-factor/challenge')
                && $status === Response::HTTP_UNPROCESSABLE_ENTITY
                && $request->hasSession()
                && $request->session()->has('login.id')
                => $this->recorder->record('mfa_fail', 'failed', $request->session()->get('login.id')),
            default => null,
        };
    }

    /**
     * The package's password login signs a two-factor user in and straight out again before asking for the code.
     * Neither step is a sign-in or a sign-out; the code step records the real one.
     */
    private function awaitsSecondFactor(Authenticatable $user): bool
    {
        return (
            request()->is(trim((string) config('auth-package.prefix'), '/') . '/login')
            && method_exists($user, 'hasEnabledTwoFactorAuthentication')
            && (bool) $user->hasEnabledTwoFactorAuthentication()
        );
    }
}
