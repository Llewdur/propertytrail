<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PilotEnquiry extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'email',
        'company',
        'portfolio_size',
        'message',
    ];
}
