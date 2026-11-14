<?php
namespace App\Services;
use App\Models\EmailSetting;
use Illuminate\Support\Facades\Mail;

class EmailConfiguration
{
    public function apply(): void
    {
        $settings = EmailSetting::find(1)?->content;
        if (! $settings) {
            return;
        }
        config(['mail.default' => $settings['driver'], 'mail.from.address' => $settings['from_address'], 'mail.from.name' => $settings['from_name']]);
        if ($settings['driver'] === 'smtp') {
            config(['mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => $settings['encryption'] === 'ssl' ? 'smtps' : 'smtp', 'host' => $settings['host'], 'port' => $settings['port'], 'username' => $settings['username'] ?? null, 'password' => $settings['password'] ?? null, 'timeout' => 15]]);
        }
        Mail::purge();
    }
}
