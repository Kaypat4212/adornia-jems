<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function checkout(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('cart')) {
            return redirect('/')->with('status', 'Add a piece to your bag first.');
        }

        return view('checkout', ['user' => $request->user(), 'lines' => app(StorefrontController::class)->cart($request)->getData()->items, 'states' => config('delivery.states'), 'subtotal' => app(StorefrontController::class)->cart($request)->getData()->total]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'phone' => 'required|string|max:30', 'address' => 'required|string|max:1000', 'city' => 'required|string|max:100', 'state' => ['required', \Illuminate\Validation\Rule::in(config('delivery.states'))], 'delivery_rate_id' => 'required|integer', 'terms' => 'accepted', 'payment_method' => 'nullable|in:cash_on_delivery,wallet,paystack,flutterwave,kora,manual']);
        $method = $data['payment_method'] ?? 'cash_on_delivery';

        if (in_array($method, ['paystack', 'flutterwave', 'kora', 'manual']) && ! app(\App\Services\PaymentGateway::class)->enabled($method)) {
            throw ValidationException::withMessages(['payment' => 'This payment gateway is not configured yet.']);
        }
        $paymentMode = in_array($method, ['wallet', 'manual', 'cash_on_delivery']) ? 'live' : app(\App\Services\GatewayConfiguration::class)->mode($method);
        $cart = $request->session()->get('cart', []);
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => 'Your bag is empty.']);
        }
        $order = DB::transaction(function () use ($request, $data, $cart, $method, $paymentMode): Order {
            $delivery = app(\App\Services\Delivery::class)->quote($data['state'], (int) $data['delivery_rate_id'], true);
            if ($method === 'cash_on_delivery' && ! $delivery->pay_on_delivery) {
                throw ValidationException::withMessages(['payment_method' => 'Pay on delivery is not available for this delivery location.']);
            }
            $order = Order::create(collect($data)->except(['terms', 'payment_method'])->all() + ['user_id' => $request->user()->id, 'reference' => 'AJ-'.Str::upper(Str::random(12)), 'total' => 0, 'payment_method' => $method, 'payment_mode' => $paymentMode, 'delivery_zone' => $delivery->name, 'delivery_fee' => $delivery->fee]);
            $total = $delivery->fee;
            foreach ($cart as $id => $quantity) {
                $product = Product::where('active', true)->lockForUpdate()->find($id);
                if (! $product || $quantity < 1 || $quantity > 99 || $product->stock < $quantity) {
                    throw ValidationException::withMessages(['cart' => 'A piece is no longer available in the requested quantity. Please update your bag.']);
                }
                $affected = $paymentMode === 'test' ? 1 : Product::whereKey($id)->where('stock', '>=', $quantity)->decrement('stock', $quantity);
                if (! $affected) {
                    throw ValidationException::withMessages(['cart' => 'Stock changed. Please try again.']);
                }
                $order->items()->create(['product_id' => $id, 'name' => $product->name, 'price' => $product->price, 'quantity' => $quantity]);
                $total += $product->price * $quantity;
            }
            $order->update(['total' => $total]);
            if ($method === 'wallet') {
                app(\App\Services\Wallet::class)->change($request->user()->id, -$total * 100, 'order:'.$order->reference, 'Purchase '.$order->reference);
                $order->update(['payment_status' => 'paid']);
            } elseif (in_array($method, ['paystack', 'flutterwave', 'kora', 'manual'])) {
                \App\Models\Payment::create(['user_id' => $request->user()->id, 'order_id' => $order->id, 'provider' => $method, 'reference' => 'AJ-'.Str::uuid(), 'amount' => $total * 100, 'mode' => $paymentMode, 'details' => $method === 'manual' ? app(\App\Services\GatewayConfiguration::class)->bank() : null]);
            }

            app(\App\Services\StoreEmail::class)->orderPlaced($order->refresh());

            return $order;
        });
        $request->session()->forget('cart');

        if (in_array($method, ['paystack', 'flutterwave', 'kora', 'manual'])) {
            return redirect()->route($method === 'manual' ? 'manual.show' : 'payments.start', \App\Models\Payment::where('order_id', $order->id)->firstOrFail()->reference);
        }
        return redirect()->route('orders.show', $order)->with('status', $method === 'wallet' ? 'Order paid from your wallet.' : 'Order placed. Payment is due on delivery.');
    }

    public function show(Request $request, Order $order): View
    {
        abort_unless($order->user_id === $request->user()->id || $request->user()->is_admin, 403);

        return view('order', ['order' => $order->load('items')]);
    }

    public function reorder(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $quantities = $request->session()->get('cart', []);
        $items = $order->items()->get();
        $products = Product::where('active', true)->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
        $added = 0;
        $requested = 0;

        foreach ($items as $item) {
            $requested += $item->quantity;
            $current = $quantities[$item->product_id] ?? 0;
            $stock = $products->get($item->product_id)?->stock ?? 0;
            $quantity = min($item->quantity, max(0, min($stock, 99) - $current));

            if ($quantity > 0) {
                $quantities[$item->product_id] = $current + $quantity;
                $added += $quantity;
            }
        }

        $request->session()->put('cart', $quantities);
        if ($added === 0) {
            return back()->with('status', 'No pieces could be added. They may be out of stock or already in your bag.');
        }

        $message = $added < $requested
            ? 'Available pieces were added. Some quantities are no longer available or are already in your bag.'
            : 'Pieces added to your bag at current prices.';

        return redirect()->route('checkout')->with('status', $message);
    }

    public function tracking(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'carrier' => 'required|string|max:80',
            'tracking_number' => 'required|string|max:120',
        ]);

        DB::transaction(function () use ($order, $data): void {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, ['confirmed', 'shipped'])) {
                throw ValidationException::withMessages(['tracking_number' => 'Tracking can be added to confirmed or shipped orders.']);
            }
            $locked->update($data);
        });

        return back()->with('status', 'Shipment tracking saved.');
    }

    public function status(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:confirmed,shipped,delivered,cancelled']);
        DB::transaction(function () use ($order, $data): void {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            $allowed = ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['shipped', 'cancelled'], 'shipped' => ['delivered'], 'delivered' => [], 'cancelled' => []];
            if (! in_array($data['status'], $allowed[$locked->status])) {
                throw ValidationException::withMessages(['status' => 'That order status change is not allowed.']);
            }
            if ($locked->payment_mode === 'test' && $data['status'] !== 'cancelled') {
                throw ValidationException::withMessages(['status' => 'Test orders cannot be fulfilled. Use live mode for real orders.']);
            }
            if ($locked->payment_method !== 'cash_on_delivery' && $locked->payment_status !== 'paid' && $data['status'] !== 'cancelled') {
                throw ValidationException::withMessages(['status' => 'Online and wallet orders must be paid before fulfillment.']);
            }
            if ($data['status'] === 'cancelled') {
                if ($locked->payment_status === 'paid') {
                    app(\App\Services\Wallet::class)->change($locked->user_id, $locked->total * 100, 'refund:'.$locked->reference, 'Cancelled order refunded to wallet', $locked->payment_mode);
                }
                foreach ($locked->items as $item) {
                    if ($locked->payment_mode !== 'test') {
                        Product::withTrashed()->whereKey($item->product_id)->increment('stock', $item->quantity);
                    }
                }
            }
            $locked->update(['status' => $data['status'], 'payment_status' => $data['status'] === 'cancelled' && $locked->payment_status === 'paid' ? 'refunded_to_wallet' : ($data['status'] === 'delivered' ? 'paid' : $locked->payment_status)]);
        });

        return back()->with('status', 'Order updated. Mark delivered only after collecting payment.');
    }
}
