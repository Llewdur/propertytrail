<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Legal;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

final class TermsController extends Controller
{
    public function __invoke(): Response
    {
        try {
            $markdown = file_get_contents(base_path(Legal::TERMS_FILE));
        } catch (Throwable) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Terms document is missing.');
        }

        if ($markdown === false) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Terms document is missing.');
        }

        $name = e((string) config('app.name'));
        $body = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html =
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Terms of Service - '
            . $name
            . '</title>'
            . '<meta name="robots" content="index, follow">'
            . '<style>body{margin:0;background:#f8fafc;color:#0f172a;font:16px/1.65 system-ui,sans-serif}'
            . 'main{max-width:48rem;margin:0 auto;padding:3rem 1.25rem}'
            . 'h1{font-size:1.8rem}h2{font-size:1.2rem;margin-top:2rem}'
            . 'a{color:#0f172a}p,li{margin:.6rem 0}</style>'
            . '</head><body><main>'
            . $body
            . '<p><a href="/">Back to '
            . $name
            . '</a></p></main></body></html>';

        return response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
