<?php
namespace App\Http\Controllers;
use App\Models\{HomepageSection, StoreSetting, User, Order, Payment, WalletEntry};
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentController extends Controller
{
    public function index(): View
    {
        return view('admin.content', ['sections' => HomepageSection::orderBy('sort_order')->orderBy('id')->get(), 'settings' => StoreSetting::find(1)?->content ?? []]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['brand' => 'required|string|max:80', 'announcement' => 'required|string|max:200', 'footer' => 'required|string|max:300', 'title' => 'required|string|max:150', 'description' => 'required|string|max:300', 'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'background' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'ink' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'shipping' => 'nullable|integer|min:0|max:100000', 'cod' => 'nullable|boolean', 'nav_collection' => 'required|string|max:40', 'nav_story' => 'required|string|max:40', 'nav_3d' => 'required|string|max:40']);
        unset($data['shipping'], $data['cod']);
        StoreSetting::updateOrCreate(['id' => 1], ['content' => array_replace(StoreSetting::find(1)?->content ?? [], $data)]);

        return back()->with('status', 'Store settings published.');
    }

    public function save(Request $request, ?HomepageSection $section = null): RedirectResponse
    {
        $upload = $request->file('image_upload');
        $uploadError = $upload instanceof \Illuminate\Http\UploadedFile ? $upload->getErrorMessage() : '';
        $data = $request->validate(['type' => 'required|in:hero,ticker,collection,story,custom', 'sort_order' => 'required|integer|min:0|max:10000', 'active' => 'nullable|boolean', 'content' => 'required|array', 'content.*' => 'nullable|string|max:5000', 'image_upload' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096'], [
            'image_upload.uploaded' => $uploadError ?: 'The photo could not be uploaded. Choose it again and retry.',
            'image_upload.max' => 'Choose a photo up to 4 MB.',
            'image_upload.image' => 'Choose a valid JPG, PNG or WebP photo.',
            'image_upload.mimes' => 'Choose a JPG, PNG or WebP photo.',
        ]);
        if ($data['type'] !== 'custom' && HomepageSection::where('type', $data['type'])->when($section, fn ($query) => $query->where('id', '!=', $section->id))->exists()) {
            return back()->withErrors(['type' => 'Only one of each built-in section is allowed. Use a custom section for additional content.']);
        }
        $allowed = ['eyebrow', 'heading', 'highlight', 'body', 'body2', 'button', 'link', 'secondary', 'secondary_link', 'ring_name', 'badge', 'hint', 'item1', 'item2', 'item3', 'note'];
        $content = collect($data['content'])->only($allowed)->map(fn ($value) => $value ?? '')->all();
        foreach (['link', 'secondary_link'] as $key) {
            if (filled($content[$key] ?? '') && ! preg_match('~^(#[a-zA-Z0-9_-]+|/(?!/)[a-zA-Z0-9/#?=&_.-]*)$~', $content[$key])) {
                return back()->withErrors(['link' => 'Use a section anchor (#collection) or a local path (/products/1).']);
            }
        }
        if ($section?->content['image'] ?? null) {
            $content['image'] = $section->content['image'];
        }
        if ($request->boolean('remove_image')) {
            unset($content['image']);
        }
        if ($request->hasFile('image_upload')) {
            $content['image'] = $request->file('image_upload')->store('homepage', 'public');
            if ($content['image'] === false) {
                throw ValidationException::withMessages(['image_upload' => 'The photo could not be saved. Check that the server can write to storage/app/public and that the hosting account has free disk space.']);
            }
        }
        ($section ?? new HomepageSection)->fill(['type' => $data['type'], 'sort_order' => $data['sort_order'], 'active' => $request->boolean('active'), 'content' => $content])->save();

        return back()->with('status', 'Homepage section published.');
    }

    public function destroy(HomepageSection $section): RedirectResponse
    {
        $section->delete();

        return back()->with('status', 'Section removed.');
    }

    public function sales(Request $request): View
    {
        return view('admin.sales', [
            'orders' => $this->salesQuery($request)->latest()->paginate(25)->withQueryString(),
            'payments' => Payment::latest()->paginate(25, ['*'], 'payment_page'),
            'entries' => WalletEntry::latest()->limit(30)->get(),
        ]);
    }

    public function exportSales(Request $request): StreamedResponse
    {
        $orders = $this->salesQuery($request);

        return response()->streamDownload(function () use ($orders): void {
            $file = fopen('php://output', 'wb');
            fputcsv($file, ['Order', 'Date', 'State', 'Delivery (NGN)', 'Total (NGN)', 'Fulfillment', 'Payment status', 'Payment method', 'Mode'], escape: '');
            foreach ($orders->lazyById() as $order) {
                $fields = [
                    $order->reference, $order->created_at->toDateTimeString(), $order->state,
                    $order->delivery_fee, $order->total, $order->status, $order->payment_status,
                    $order->payment_method, $order->payment_mode,
                ];
                $fields = array_map(
                    fn ($value) => is_string($value) && preg_match('/^[\s=+@-]/', $value) ? "'".$value : $value,
                    $fields,
                );
                fputcsv($file, $fields, escape: '');
            }
            fclose($file);
        }, 'adornia-sales.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function salesQuery(Request $request): Builder
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:pending,confirmed,shipped,delivered,cancelled',
            'mode' => 'nullable|in:test,live',
        ]);

        return Order::query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['mode'] ?? null, fn ($query, $mode) => $query->where('payment_mode', $mode))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where('reference', 'like', '%'.$search.'%'));
    }

    public function customers(): View
    {
        return view('admin.customers', ['customers' => User::latest()->paginate(25)]);
    }

    public function customer(User $user): View
    {
        return view('admin.customer', ['customer' => $user, 'accounts' => \App\Models\RefundAccount::where('user_id', $user->id)->latest()->get(), 'orders' => Order::where('user_id', $user->id)->latest()->paginate(10)]);
    }

    public function credentials(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($request->user()->id)], 'current_password' => 'required|current_password', 'password' => 'required|confirmed|min:12|max:128']);
        $request->user()->forceFill(['email' => $data['email'], 'password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => \Illuminate\Support\Str::random(60)])->save();
        $request->session()->regenerate();

        return back()->with('status', 'Administrator credentials updated.');
    }
}
