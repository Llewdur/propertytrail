<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of every use of a public report link, good or refused.
 * Unscoped by design: a refused attempt has no resolved organisation.
 *
 * @property int $id
 * @property int|null $report_link_id
 * @property int|null $organisation_id
 * @property string $outcome
 * @property string|null $ip
 */
final class ReportAccessLog extends Model
{
    public const UPDATED_AT = null;

    /** @var array<int, string> */
    protected $fillable = ['report_link_id', 'organisation_id', 'outcome', 'ip'];
}
