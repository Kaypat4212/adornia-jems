<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['details' => 'array'];
    }
    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->mode ??= $payment->provider === 'manual' ? 'live' : app(\App\Services\GatewayConfiguration::class)->mode($payment->provider);
        });
    }
}
