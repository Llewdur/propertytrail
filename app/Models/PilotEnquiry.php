<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PilotEnquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PilotEnquiry extends Model
{
    /** @use HasFactory<PilotEnquiryFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'email',
        'company',
        'portfolio_size',
        'message',
    ];
}
