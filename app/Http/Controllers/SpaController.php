<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;
use Zekini\Auth\Support\AuthBootstrap;

final class SpaController extends Controller
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    public function __invoke(Request $request): Response
    {
        $shellPath = resource_path('spa/index.html');

        try {
            $html = file_get_contents($shellPath);
        } catch (Throwable) {
            $html = false;
        }

        if ($html === false) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'SPA shell is missing.');
        }

        $bootstrap = [
            'appName' => config('app.name'),
            'version' => config('app.version'),
            'termsUrl' => url('/terms'),
            'currentOrganisationId' => $this->currentOrganisation->id(),
            'googleEnabled' =>
                is_string(config('services.google.client_id'))
                    && config('services.google.client_id') !== ''
                    && is_string(config('services.google.client_secret'))
                    && config('services.google.client_secret') !== '',
            ...AuthBootstrap::for($request),
        ];

        $script =
            '<script>window.__PROPERTYTRAIL_BOOTSTRAP__=' . json_encode($bootstrap, JSON_THROW_ON_ERROR) . ';</script>';
        $html = str_replace('</head>', $script . '</head>', $html);

        return response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'",
        ]);
    }
}
