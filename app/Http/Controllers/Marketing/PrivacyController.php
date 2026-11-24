<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\Legal;
use App\Support\PublicPage;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

final class PrivacyController extends Controller
{
    public function __invoke(): Response
    {
        try {
            $markdown = file_get_contents(base_path(Legal::PRIVACY_FILE));
        } catch (Throwable) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Privacy notice is missing.');
        }

        if ($markdown === false) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Privacy notice is missing.');
        }

        return response()
            ->view('marketing.privacy', [
                'title' => 'Privacy notice (POPIA)',
                'description' => 'What personal information PropertyTrail processes, why, and the choices available to you.',
                'body' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ])
            ->withHeaders(PublicPage::headers());
    }
}
