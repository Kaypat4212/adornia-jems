<?php

namespace App\Services;

use App\Models\EmailSetting;
use App\Models\GatewaySetting;
use Throwable;

class LogRedactor
{
    public function clean(string $text): string
    {
        $secrets = [config('app.key'), config('database.connections.mysql.password'), config('mail.mailers.smtp.password')];
        try {
            $secrets[] = EmailSetting::find(1)?->content['password'] ?? null;
            foreach (GatewaySetting::all() as $gateway) {
                $credentials = $gateway->credentials ?? [];
                array_walk_recursive($credentials, function (mixed $value) use (&$secrets): void {
                    $secrets[] = $value;
                });
            }
        } catch (Throwable) {
            // Database errors must not prevent viewing the application log.
        }
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        $text = preg_replace('~([a-z][a-z0-9+.-]*://)[^\s/@]+:[^\s/@]+@~i', '$1[redacted]@', $text) ?? '';
        $text = preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/s', '[private key redacted]', $text) ?? '';

        return preg_replace('/^.*(?:password|passwd|secret|token|authorization|cookie|app_key|api[_-]?key|Bearer\s|AUTH\s+(?:LOGIN|PLAIN)).*$/mi', '[credential details redacted]', $text) ?? '[Log unavailable]';
    }
}
