<?php

namespace App\Services;

class VisitorDevice
{
    public function type(?string $userAgent): string
    {
        $userAgent = substr($userAgent ?? '', 0, 2048);
        if (preg_match('/bot|crawler|spider|slurp|bingpreview|headless/i', $userAgent)) {
            return 'Bot';
        }
        if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $userAgent)
            || (stripos($userAgent, 'Android') !== false && stripos($userAgent, 'Mobile') === false)
            || (stripos($userAgent, 'Macintosh') !== false && stripos($userAgent, 'Mobile') !== false)) {
            return 'Tablet';
        }
        if (preg_match('/mobile|iphone|ipod|android|windows phone|opera mini|blackberry/i', $userAgent)) {
            return 'Mobile';
        }
        if (preg_match('/windows nt|macintosh|x11|cros|linux/i', $userAgent)) {
            return 'Desktop';
        }

        return 'Unknown';
    }
}
