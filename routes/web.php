<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{StorefrontController, AuthController, AdminController, OrderController};
use App\Http\Controllers\InstallController;
use App\Http\Middleware\AdminOnly;
use App\Models\Product;
use Illuminate\Http\Request;

Route::get('/', [StorefrontController::class, 'index'])->name('storefront');
Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');
Route::get('/products/{product}', function (Product $product) {
    abort_unless($product->active, 404);
    return view('product', compact('product'));
})->name('products.show');
Route::get('/bag', [StorefrontController::class, 'cart'])->name('bag.show');
Route::post('/bag', [StorefrontController::class, 'add'])->block()->name('bag.add');
Route::delete('/bag/{product}', [StorefrontController::class, 'remove'])->whereNumber('product')->block()->name('bag.remove');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::get('/register', [AuthController::class, 'form'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::get('/forgot-password', [AuthController::class, 'form'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
    Route::get('/reset-password/{token}', [AuthController::class, 'form'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/account', [AuthController::class, 'account'])->name('account');
    Route::put('/account', [AuthController::class, 'update']);
    Route::get('/checkout', [OrderController::class, 'checkout'])->name('checkout');
    Route::post('/checkout', [OrderController::class, 'store'])->block()->middleware('throttle:10,1');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/reorder', [OrderController::class, 'reorder'])->block()->name('orders.reorder');
    Route::get('/wishlist', function (Request $request) {
        return view('wishlist', ['products' => $request->user()->wishlist()->where('active', true)->get()]);
    })->name('wishlist');
    Route::post('/wishlist/{product}', function (Request $request, Product $product) {
        abort_unless($product->active, 404);
        $request->user()->wishlist()->toggle($product->id);
        return back()->with('status', 'Wishlist updated.');
    })->block();
});
Route::prefix('admin')->middleware(['auth', AdminOnly::class])->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('admin');
    Route::resource('products', AdminController::class)->except(['index', 'show'])->names('admin.products');
    Route::put('/orders/{order}', [OrderController::class, 'status'])->name('admin.orders.update');
    Route::put('/orders/{order}/tracking', [OrderController::class, 'tracking'])->name('admin.orders.tracking');
});

Route::post('/payments/webhook/{provider}/{mode?}', [\App\Http\Controllers\PaymentController::class, 'webhook'])->whereIn('provider', ['paystack', 'flutterwave', 'kora'])->whereIn('mode', ['test', 'live'])->name('payments.webhook');
Route::middleware('auth')->group(function () {
    Route::get('/wallet', [\App\Http\Controllers\PaymentController::class, 'wallet'])->name('wallet');
    Route::post('/wallet/deposit', [\App\Http\Controllers\PaymentController::class, 'deposit'])->middleware('throttle:10,1');
    Route::get('/payments/{payment:reference}/start', [\App\Http\Controllers\PaymentController::class, 'start'])->middleware('throttle:10,1')->name('payments.start');
    Route::get('/payments/{payment:reference}/callback', [\App\Http\Controllers\PaymentController::class, 'callback'])->middleware('throttle:20,1')->name('payments.callback');
});
Route::prefix('admin')->middleware(['auth', AdminOnly::class])->group(function () {
    Route::get('/homepage', [\App\Http\Controllers\ContentController::class, 'index'])->name('admin.homepage');
    Route::put('/settings', [\App\Http\Controllers\ContentController::class, 'settings']);
    Route::post('/sections', [\App\Http\Controllers\ContentController::class, 'save']);
    Route::put('/sections/{section}', [\App\Http\Controllers\ContentController::class, 'save']);
    Route::delete('/sections/{section}', [\App\Http\Controllers\ContentController::class, 'destroy']);
    Route::get('/sales', [\App\Http\Controllers\ContentController::class, 'sales']);
    Route::get('/sales/export', [\App\Http\Controllers\ContentController::class, 'exportSales'])->name('admin.sales.export');
    Route::get('/customers', [\App\Http\Controllers\ContentController::class, 'customers']);
    Route::get('/customers/{user}', [\App\Http\Controllers\ContentController::class, 'customer']);
    Route::get('/credentials', fn () => view('admin.credentials'));
    Route::put('/credentials', [\App\Http\Controllers\ContentController::class, 'credentials'])->middleware('throttle:5,1');
});

Route::get('/admin/login', fn () => view('auth', ['mode' => 'login', 'token' => null]))->middleware('guest')->name('admin.login');

Route::middleware('auth')->group(function () {
    Route::get('/account/banks', [\App\Http\Controllers\RefundAccountController::class, 'banks'])->middleware('throttle:20,1');
    Route::post('/account/refund-accounts', [\App\Http\Controllers\RefundAccountController::class, 'store'])->middleware('throttle:5,1')->block();
    Route::delete('/account/refund-accounts/{account}', [\App\Http\Controllers\RefundAccountController::class, 'destroy'])->block();
    Route::get('/payments/{payment:reference}/manual', [\App\Http\Controllers\ManualPaymentController::class, 'show'])->name('manual.show');
    Route::post('/payments/{payment:reference}/manual', [\App\Http\Controllers\ManualPaymentController::class, 'submit'])->middleware('throttle:5,1');
});
Route::prefix('admin')->middleware(['auth', AdminOnly::class])->group(function () {
    Route::get('/payment-settings', [\App\Http\Controllers\GatewaySettingsController::class, 'index'])->name('admin.payment-settings');
    Route::put('/payment-settings/bank', [\App\Http\Controllers\GatewaySettingsController::class, 'bank'])->middleware('throttle:5,1');
    Route::put('/payment-settings/{provider}', [\App\Http\Controllers\GatewaySettingsController::class, 'update'])->middleware('throttle:10,1');
    Route::get('/payments/{payment}/receipt', [\App\Http\Controllers\ManualPaymentController::class, 'receipt']);
    Route::post('/payments/{payment}/review', [\App\Http\Controllers\ManualPaymentController::class, 'review'])->middleware('throttle:10,1');
});

Route::get('/checkout/delivery-options', [\App\Http\Controllers\DeliveryController::class, 'options'])->middleware('auth');
Route::prefix('admin')->middleware(['auth', AdminOnly::class])->group(function () {
    Route::get('/delivery', [\App\Http\Controllers\DeliveryController::class, 'index']);
    Route::post('/delivery', [\App\Http\Controllers\DeliveryController::class, 'save']);
    Route::put('/delivery/{rate}', [\App\Http\Controllers\DeliveryController::class, 'save']);
    Route::delete('/delivery/{rate}', [\App\Http\Controllers\DeliveryController::class, 'destroy']);
    Route::get('/email-settings', [\App\Http\Controllers\EmailSettingsController::class, 'index']);
    Route::put('/email-settings', [\App\Http\Controllers\EmailSettingsController::class, 'save'])->middleware('throttle:5,1');
    Route::post('/email-deliveries/{delivery}/retry', [\App\Http\Controllers\EmailSettingsController::class, 'retry'])->middleware('throttle:10,1');
});
