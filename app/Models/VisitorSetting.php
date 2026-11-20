<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitorSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array'];
    }

    public static function values(): array
    {
        return array_replace([
            'captcha_enabled' => (bool) config('visitors.captcha_enabled', true),
            'email_enabled' => true, 'telegram_enabled' => false,
            'telegram_token' => '', 'telegram_chat' => '', 'loader' => 'bar',
        ], static::find(1)?->content ?? []);
    }
}
