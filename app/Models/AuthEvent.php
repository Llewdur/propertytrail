<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event
 * @property string $outcome
 * @property int|null $user_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $occurred_at
 */
final class AuthEvent extends Model
{
    use MassPrunable;

    /** The same period Pulse keeps them. */
    public const RETENTION_DAYS = 90;

    public $timestamps = false;

    /** @var array<int, string> */
    protected $fillable = ['event', 'outcome', 'user_id', 'ip', 'user_agent', 'occurred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('occurred_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
