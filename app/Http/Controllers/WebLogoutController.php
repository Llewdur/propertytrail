<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class WebLogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var StatefulGuard $guard */
        $guard = Auth::guard('web');
        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/app');
    }
}
