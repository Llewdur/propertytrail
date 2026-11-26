<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\PublicPage;
use Illuminate\Http\Response;

final class ResourcesController extends Controller
{
    /** @var array<string, array{title: string, description: string, sections: list<array{heading: string, body: string}>}> */
    private const ARTICLES = [
        'rental-housing-tribunal-complaints' => [
            'title' => 'Preparing for a rental housing complaint',
            'description' => 'Keep the record together: what was agreed, what was observed and when.',
            'sections' => [
                [
                    'heading' => 'Start with the record you have',
                    'body' => 'Gather the lease, the move-in and move-out condition records, photographs and any correspondence. A dated record made at the time is easier to work with than recollections later. PropertyTrail keeps those items with the property so they can be exported together.',
                ],
                [
                    'heading' => 'Keep the parties informed',
                    'body' => 'Share the signed report with the other party using a private link and record that you did so. Clear communication about the condition notes often resolves a disagreement before it becomes a complaint.',
                ],
                [
                    'heading' => 'Get advice where you need it',
                    'body' => 'A condition report is evidence of what was recorded, not a legal opinion. For a complaint or a legal question, speak to the Rental Housing Tribunal or your own adviser.',
                ],
            ],
        ],
        'fair-wear-and-tear' => [
            'title' => 'Fair wear and tear: what the condition notes should say',
            'description' => 'Ordinary use is different from damage. Record the evidence either way.',
            'sections' => [
                [
                    'heading' => 'Describe what you can see',
                    'body' => 'Measurable notes such as a 20 cm scuff, a loose handle or a missing shelf are more useful than "damaged" or "fine". Add the age of the item where you know it, so ordinary wear can be distinguished from new damage.',
                ],
                [
                    'heading' => 'Compare like with like',
                    'body' => 'An outgoing inspection is most useful when there is an incoming record to compare it with. Keep both against the same room and item list, and mark items that were already like that at move-in.',
                ],
                [
                    'heading' => 'Photographs support the note',
                    'body' => 'Attach a photograph to the item it belongs to. PropertyTrail records what was captured; it does not claim the photographs are independently verified or legally conclusive.',
                ],
            ],
        ],
        'can-a-report-be-used-in-court' => [
            'title' => 'Can a property report be used in court?',
            'description' => 'A report is a record of condition. Whether it is accepted is not for us to say.',
            'sections' => [
                [
                    'heading' => 'What the report is',
                    'body' => 'It is a dated record of the condition observed at an inspection, with the notes, photographs and signatures kept together. Both parties sign the same inspection and the PDF is generated once those signatures are in.',
                ],
                [
                    'heading' => 'What the report is not',
                    'body' => 'It is not a legal opinion and it does not decide a dispute. PropertyTrail does not claim that its photographs are independently verified or legally conclusive, and it does not make claims about admissibility.',
                ],
                [
                    'heading' => 'Ask the right person',
                    'body' => 'If you need to know whether a document can be used in a proceeding, ask a legal practitioner or the relevant tribunal. Keep the original record, the signed PDF and the access history available for them to review.',
                ],
            ],
        ],
        'missed-inspections' => [
            'title' => 'What to do when the move-in inspection was missed',
            'description' => 'A late record is still better than no record.',
            'sections' => [
                [
                    'heading' => 'Record the current condition',
                    'body' => 'Walk the property and capture the condition as it is now, room by room. Note the date of the inspection and that no earlier record was made, so the timing is clear to anyone who reads it later.',
                ],
                [
                    'heading' => 'Agree the baseline going forward',
                    'body' => 'Ask the other party to review and sign the record so there is an agreed starting point for the rest of the lease. A shared baseline prevents the same question recurring at move-out.',
                ],
                [
                    'heading' => 'Keep it consistent',
                    'body' => 'Use the same room list for the outgoing inspection so the two records can be compared. Ready-made room checklists are available in PropertyTrail and in our printable guide.',
                ],
            ],
        ],
    ];

    public function __invoke(): Response
    {
        return response()
            ->view('marketing.resources', [
                'title' => 'Resources for South African landlords and tenants',
                'description' => 'A free printable move-in and move-out checklist and reading for South African property condition questions.',
                'articles' => self::ARTICLES,
            ])
            ->withHeaders(PublicPage::headers());
    }

    public function article(string $slug): Response
    {
        $article = self::ARTICLES[$slug] ?? null;

        if ($article === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()
            ->view('marketing.article', [
                'title' => $article['title'],
                'description' => $article['description'],
                'article' => $article,
            ])
            ->withHeaders(PublicPage::headers());
    }
}
