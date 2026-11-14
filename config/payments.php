<?php

return [
    'default_mode' => env('PAYMENT_MODE', 'test'),
    'paystack' => ['secret' => env('PAYSTACK_SECRET_KEY'), 'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET')],
    'flutterwave' => ['secret' => env('FLUTTERWAVE_SECRET_KEY'), 'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET')],
    'kora' => ['secret' => env('KORA_SECRET_KEY')],
];
