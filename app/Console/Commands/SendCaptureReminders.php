<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CaptureReminderService;
use Illuminate\Console\Command;

final class SendCaptureReminders extends Command
{
    protected $signature = 'capture:send-reminders';

    protected $description = 'Remind tenants to finish photographing their property, by push or WhatsApp.';

    public function handle(CaptureReminderService $reminders): int
    {
        $this->info('Reminders sent: ' . $reminders->sendDue());

        return self::SUCCESS;
    }
}
