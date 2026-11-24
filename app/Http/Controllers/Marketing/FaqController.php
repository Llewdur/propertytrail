<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\PublicPage;
use Illuminate\Http\Response;

final class FaqController extends Controller
{
    public function __invoke(): Response
    {
        return response()
            ->view('marketing.faq', [
                'title' => 'Frequently asked questions',
                'description' => 'Answers about property condition reports, fair wear and tear, missed inspections and rental housing disputes.',
            ])
            ->withHeaders(PublicPage::headers());
    }
}
