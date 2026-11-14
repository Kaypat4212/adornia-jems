<?php
namespace App\Services;
use App\Models\{EmailDelivery, EmailSetting, User, Order, Payment};
use App\Jobs\DeliverStoreEmail;

class StoreEmail
{
    public function enqueue(string $event, string $recipient, string $subject, string $message, string $path): void
    {
        $delivery = EmailDelivery::firstOrCreate(['event_key' => hash('sha256', $event.':'.strtolower($recipient))], ['recipient' => $recipient, 'subject' => $subject, 'message' => $message, 'action_path' => $path]);
        if ($delivery->wasRecentlyCreated) {
            DeliverStoreEmail::dispatch($delivery->id)->onConnection('database')->onQueue('emails')->afterCommit();
        }
    }

    public function admins(string $event, string $subject, string $message, string $path): void
    {
        $configured = EmailSetting::find(1)?->content['admin_email'] ?? null;
        $addresses = $configured ? [$configured] : User::where('is_admin', true)->pluck('email')->all();
        foreach (array_unique($addresses) as $email) {
            $this->enqueue($event, $email, $subject, $message, $path);
        }
    }

    public function welcome(User $user): void
    {
        $this->enqueue('welcome:'.$user->id, $user->email, 'Welcome to Adornia Jems', 'Your account is ready. Discover your next signature piece and manage orders from your account.', '/account');
    }

    public function orderPlaced(Order $order): void
    {
        $user = User::findOrFail($order->user_id);
        $message = 'Order '.$order->reference.' has been placed. Total: NGN '.number_format($order->total).', including delivery of NGN '.number_format($order->delivery_fee).'.';
        $message .= $order->payment_method === 'manual' ? ' Transfer to the company account and upload proof using the payment link on your order. Your payment awaits admin confirmation.' : ' Payment status: '.$order->payment_status.'.';
        $this->enqueue('order:'.$order->id, $user->email, 'Order received · '.$order->reference, $message, '/orders/'.$order->id);
        $this->admins('admin-order:'.$order->id, 'New order · '.$order->reference, $message, '/orders/'.$order->id);
    }

    public function orderUpdated(Order $order): void
    {
        $user = User::findOrFail($order->user_id);
        $message = 'Order '.$order->reference.' is now '.$order->status.'.';
        if ($order->payment_status === 'refunded_to_wallet') {
            $message .= ' Your payment was refunded to your '.($order->payment_mode === 'test' ? 'test ' : '').'platform wallet.';
        }
        $this->enqueue('order-status:'.$order->id.':'.$order->status, $user->email, 'Order update · '.$order->reference, $message, '/orders/'.$order->id);
    }

    public function paymentUpdated(Payment $payment): void
    {
        if (! in_array($payment->status, ['under_review', 'succeeded', 'rejected'])) {
            return;
        }
        $subject = match ($payment->status) {'under_review' => 'Transfer proof received', 'succeeded' => 'Payment confirmed', 'rejected' => 'Transfer proof needs attention'};
        $message = $subject.' for '.strtoupper($payment->mode).' payment '.$payment->reference.' of NGN '.number_format($payment->amount / 100, 2).'.';
        if ($payment->status === 'rejected') {
            $message .= ' Please review the admin note and upload corrected proof.';
        }
        $user = User::findOrFail($payment->user_id);
        $path = $payment->provider === 'manual' ? '/payments/'.$payment->reference.'/manual' : ($payment->order_id ? '/orders/'.$payment->order_id : '/wallet');
        $event = 'payment:'.$payment->id.':'.$payment->status.':'.hash('sha256', (string) $payment->receipt);
        $this->enqueue($event, $user->email, $subject, $message, $path);
        $this->admins('admin-'.$event, $subject, $message, '/admin/sales');
    }
}
