<?php
namespace App\Jobs;
use App\Models\EmailDelivery;
use App\Mail\StoreMessage;
use App\Services\EmailConfiguration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Mail};

class DeliverStoreEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('store-email:'.$this->deliveryId))->releaseAfter(10)->expireAfter(60)];
    }

    public function handle(EmailConfiguration $configuration): void
    {
        $delivery = EmailDelivery::findOrFail($this->deliveryId);
        if (in_array($delivery->status, ['sent', 'logged'])) {
            return;
        }
        $delivery->increment('attempts');
        try {
            $configuration->apply();
            Mail::to($delivery->recipient)->send(new StoreMessage($delivery->subject, $delivery->message, $delivery->action_path ?? '/account'));
            $delivery->update(['status' => config('mail.default') === 'log' ? 'logged' : 'sent', 'sent_at' => now(), 'last_error' => null]);
        } catch (\Throwable $exception) {
            $delivery->update(['status' => 'failed', 'last_error' => 'Email delivery failed. Check SMTP settings and server connectivity, then retry.']);
            throw $exception;
        }
    }
}
