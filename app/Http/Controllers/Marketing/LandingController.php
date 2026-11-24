<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\PublicPage;
use Illuminate\Http\Response;

final class LandingController extends Controller
{
    public function __invoke(): Response
    {
        return response()
            ->view('marketing.landing', [
                'title' => 'Property condition records that stay with the property',
                'description' => 'Capture a property condition on your phone, collect both signatures and keep a signed PDF with the record.',
            ])
            ->withHeaders(PublicPage::headers());
    }
}
