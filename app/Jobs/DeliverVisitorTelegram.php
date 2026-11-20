<?php

namespace App\Jobs;

use App\Models\Visitor;
use App\Models\VisitorAlert;
use App\Models\VisitorSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverVisitorTelegram implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 20;

    public function __construct(public int $alertId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        $lock = Cache::lock('visitor-telegram:'.$this->alertId, 60);
        if (! $lock->get()) {
            if ($this->job) {
                $this->release(10);
            }
            return;
        }
        try {
            $alert = VisitorAlert::findOrFail($this->alertId);
            if (in_array($alert->telegram_status, ['sent', 'disabled'])) {
                return;
            }
            $settings = VisitorSetting::values();
            if (! $settings['telegram_enabled']) {
                $alert->update(['telegram_status' => 'disabled']);
                return;
            }
            $visitor = Visitor::findOrFail($alert->visitor_id);
            try {
                $response = Http::timeout(10)->post('https://api.telegram.org/bot'.$settings['telegram_token'].'/sendMessage', [
                    'chat_id' => $settings['telegram_chat'],
                    'text' => 'Adornia Jems · '.$alert->reason."\nVisitor #".$visitor->id.' · '.$visitor->maskedIp()."\n".url('/admin/visitors'),
                ]);
                if (! $response->successful() || $response->json('ok') !== true) {
                    throw new RuntimeException('Telegram rejected the visitor alert. Check the bot token, chat ID, and bot permissions.');
                }
                $alert->update(['telegram_status' => 'sent', 'telegram_sent_at' => now(), 'last_error' => null]);
            } catch (Throwable) {
                $message = 'Telegram alert failed. Check bot credentials, chat permissions, and server connectivity.';
                $alert->update(['telegram_status' => 'failed', 'last_error' => $message]);
                throw new RuntimeException($message);
            }
        } finally {
            $lock->release();
        }
    }
}
