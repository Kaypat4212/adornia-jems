<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ip_address' => 'encrypted', 'verified_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function maskedIp(): string
    {
        $ip = $this->ip_address;
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
        }

        return preg_replace('/\.[^.]+$/', '.x', $ip) ?? 'Unknown';
    }
}
