<?php
namespace App\Http\Controllers;
use App\Models\{EmailSetting, EmailDelivery};
use App\Jobs\DeliverStoreEmail;
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use App\Services\EmailConfiguration;
use Illuminate\Support\Str;
use Throwable;

class EmailSettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.email-settings', ['settings' => EmailSetting::find(1)?->content ?? [], 'deliveries' => EmailDelivery::latest()->paginate(20)]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), ['current_password' => 'required|current_password', 'driver' => 'required|in:smtp,log', 'from_address' => 'required|email|max:254', 'from_name' => 'required|string|max:100', 'admin_email' => 'required|email|max:254', 'host' => ['nullable', 'required_if:driver,smtp', 'regex:/^[a-zA-Z0-9.-]+$/', 'max:253'], 'port' => 'nullable|required_if:driver,smtp|integer|min:1|max:65535', 'encryption' => 'required|in:tls,ssl', 'username' => 'nullable|string|max:254', 'password' => 'nullable|string|max:2048']);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $data = $validator->validated();
        unset($data['current_password']);
        if (! filled($data['password'] ?? null)) {
            $data['password'] = EmailSetting::find(1)?->content['password'] ?? null;
        }
        EmailSetting::updateOrCreate(['id' => 1], ['content' => $data]);

        return back()->with('status', 'Email settings saved. New messages use these settings.');
    }

    public function retry(EmailDelivery $delivery): RedirectResponse
    {
        if (! in_array($delivery->status, ['pending', 'failed', 'logged'])) {
            return back()->withErrors(['email' => 'This notification has already been sent.']);
        }

        return $this->sendNow($delivery);
    }

    public function test(Request $request, EmailConfiguration $configuration): RedirectResponse
    {
        $data = $request->validate(['recipient' => 'required|email|max:254']);
        $configuration->apply();
        if (config('mail.default') !== 'smtp') {
            return back()->withErrors(['email' => 'Save SMTP delivery mode and settings before sending a test email.']);
        }
        $delivery = EmailDelivery::create([
            'event_key' => 'smtp-test:'.Str::uuid(),
            'recipient' => $data['recipient'],
            'subject' => 'Adornia Jems SMTP test',
            'message' => 'This test email confirms that your saved SMTP settings can send messages from Adornia Jems.',
            'action_path' => '/admin/email-settings',
        ]);

        return $this->sendNow($delivery);
    }

    private function sendNow(EmailDelivery $delivery): RedirectResponse
    {
        try {
            (new DeliverStoreEmail($delivery->id, retryLogged: true))->handle(app(EmailConfiguration::class));
        } catch (Throwable) {
            return back()->withErrors(['email' => 'Email delivery failed. Review the notification error below and check your SMTP settings.']);
        }
        $delivery->refresh();
        if ($delivery->status === 'logged') {
            return back()->with('status', 'Message recorded in log mode. Select SMTP mode to send email.');
        }
        if ($delivery->status !== 'sent') {
            return back()->with('status', 'Another attempt may be running. Refresh the delivery log shortly.');
        }

        return back()->with('status', 'The mail server accepted the email. Check the recipient inbox and spam folder.');
    }
}
