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

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect('/app?auth_error=google');
        }

        $user = User::query()->where('google_id', $googleUser->getId())->first();

        if ($user === null && is_string($googleUser->getEmail())) {
            $user = User::query()->where('email', $googleUser->getEmail())->first();
        }

        if ($user === null) {
            $organisation = $this->organisations->create([
                'name' => ($googleUser->getName() ?: 'My') . ' Organisation',
            ]);

            $user = $this->users->create([
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'PropertyTrail User',
                'email' => $googleUser->getEmail() ?? Str::lower(Str::random(12)) . '@example.invalid',
                'password' => Str::random(32),
            ]);

            $user->forceFill(['google_id' => $googleUser->getId(), 'email_verified_at' => now()])->save();
            $this->users->attachToOrganisation($user, $organisation, 'admin');
        } elseif ($user->google_id === null) {
            $user->forceFill(['google_id' => $googleUser->getId()])->save();
        }

        Auth::login($user);
        $request->session()->put('two_factor_verified', true);

        if (!$this->users->hasAcceptedTerms($user)) {
            $this->users->acceptTerms($user, TermsAcceptanceSource::Social);
        }

        return redirect('/app');
    }
}
