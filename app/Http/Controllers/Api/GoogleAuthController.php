<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\OrganisationWriteFactory;
use App\Factories\UserWriteFactory;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;
use Zekini\Auth\Enums\TermsAcceptanceSource;

final class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly UserWriteFactory $users,
        private readonly OrganisationWriteFactory $organisations,
    ) {}

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Only an address Google has verified may create an account or reach an existing one, and only a
     * locally verified account is linked, because someone else may have registered an unverified one.
     * Google does not stand in for two factor authentication: such an account goes to the code step.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect('/app?auth_error=google');
        }

        /** @var User|null $user */
        $user = User::query()->where('google_id', $googleUser->getId())->first();

        if ($user === null) {
            $email = $googleUser->getEmail();

            if (
                !is_string($email)
                || !$googleUser instanceof AbstractUser
                || ($googleUser->getRaw()['email_verified'] ?? null) !== true
            ) {
                return redirect('/app?auth_error=google');
            }

            /** @var User|null $user */
            $user = User::query()
                ->whereRaw('lower(email) = ?', [Str::lower($email)])
                ->first();

            if ($user === null) {
                $organisation = $this->organisations->create([
                    'name' => ($googleUser->getName() ?: 'My') . ' Organisation',
                ]);

                $user = $this->users->create([
                    'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'PropertyTrail User',
                    'email' => $email,
                    'password' => Str::random(32),
                ]);

                $user->forceFill(['google_id' => $googleUser->getId(), 'email_verified_at' => now()])->save();
                $this->users->attachToOrganisation($user, $organisation, 'admin');
            } elseif ($user->getAttribute('email_verified_at') === null || $user->getAttribute('google_id') !== null) {
                return redirect('/app?auth_error=google_link');
            }
        }

        if ($user->getAttribute('two_factor_confirmed_at') !== null) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect('/app?auth_error=google_two_factor');
        }

        if ($user->getAttribute('google_id') === null) {
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('two_factor_verified', true);

        if (!$this->users->hasAcceptedTerms($user)) {
            $this->users->acceptTerms($user, TermsAcceptanceSource::Social);
        }

        return redirect('/app');
    }
}
