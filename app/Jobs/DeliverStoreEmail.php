<?php
namespace App\Jobs;
use App\Models\EmailDelivery;
use App\Mail\StoreMessage;
use App\Services\EmailConfiguration;
use App\Services\LogRedactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{Cache, Log, Mail};

class DeliverStoreEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public bool $retryLogged = false;

    public function __construct(public int $deliveryId, bool $retryLogged = false)
    {
        $this->retryLogged = $retryLogged;
    }

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
        $lock = Cache::lock('store-email-send:'.$this->deliveryId, 120);
        if (! $lock->get()) {
            if ($this->job) {
                $this->release(10);
            }
            return;
        }
        try {
            $this->deliver($configuration);
        } finally {
            $lock->release();
        }
    }

    private function deliver(EmailConfiguration $configuration): void
    {
        $delivery = EmailDelivery::findOrFail($this->deliveryId);
        if ($delivery->status === 'sent' || ($delivery->status === 'logged' && ! $this->retryLogged)) {
            return;
        }
        $delivery->increment('attempts');
        try {
            $configuration->apply();
            $delivery->update(['transport' => config('mail.default')]);
            $sent = Mail::to($delivery->recipient)->send(new StoreMessage($delivery->subject, $delivery->message, $delivery->action_path ?? '/account'));
            $delivery->update(['status' => config('mail.default') === 'log' ? 'logged' : 'sent', 'sent_at' => now(), 'last_error' => null, 'message_id' => $sent?->getMessageId()]);
        } catch (\Throwable $exception) {
            $error = mb_substr(app(LogRedactor::class)->clean($exception->getMessage()), 0, 1500);
            $delivery->update(['status' => 'failed', 'last_error' => $error, 'sent_at' => null]);
            Log::warning('Store email delivery failed.', ['delivery_id' => $delivery->id, 'error' => $error]);
            throw $exception;
        }
    }
}
