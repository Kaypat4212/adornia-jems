<?php
namespace App\Http\Controllers;
use App\Models\{HomepageSection, StoreSetting, User, Order, Payment, WalletEntry};
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
        $data = $request->validate(['type' => 'required|in:hero,ticker,collection,story,custom', 'sort_order' => 'required|integer|min:0|max:10000', 'active' => 'nullable|boolean', 'content' => 'required|array', 'content.*' => 'nullable|string|max:5000', 'image_upload' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096']);
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
        $orders = Order::query()->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))->when($request->filled('q'), fn ($query) => $query->where('reference', 'like', '%'.$request->string('q').'%'));

        return view('admin.sales', ['orders' => $orders->latest()->paginate(25)->withQueryString(), 'payments' => Payment::latest()->paginate(25, ['*'], 'payment_page'), 'entries' => WalletEntry::latest()->limit(30)->get()]);
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
