<?php
namespace App\Http\Controllers;
use App\Models\DeliveryRate;
use App\Services\Delivery;
use Illuminate\Http\{Request, RedirectResponse, JsonResponse};
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    public function index(): View
    {
        return view('admin.delivery', ['rates' => DeliveryRate::orderBy('state')->orderBy('name')->get(), 'states' => config('delivery.states')]);
    }

    public function save(Request $request, ?DeliveryRate $rate = null): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'state' => ['required', Rule::in(['*', ...config('delivery.states')])], 'fee' => 'nullable|integer|min:0|max:1000000', 'estimate' => 'nullable|string|max:150', 'active' => 'nullable|boolean', 'pay_on_delivery' => 'nullable|boolean']);
        if ($request->boolean('active') && ! $request->filled('fee')) {
            return back()->withErrors(['fee' => 'Set a delivery fee before activating this zone. Zero is allowed for free delivery.']);
        }
        if ($data['state'] === '*' && $request->boolean('pay_on_delivery')) {
            return back()->withErrors(['pay_on_delivery' => 'Pay on delivery must be enabled for specific states. Create a state-specific zone instead of enabling it for all interstate deliveries.']);
        }
        $data['active'] = $request->boolean('active');
        $data['pay_on_delivery'] = $request->boolean('pay_on_delivery');
        ($rate ?? new DeliveryRate)->fill($data)->save();

        return back()->with('status', 'Delivery zone saved. Existing order fees stay unchanged.');
    }

    public function destroy(DeliveryRate $rate): RedirectResponse
    {
        $rate->update(['active' => false]);

        return back()->with('status', 'Delivery zone disabled.');
    }

    public function options(Request $request, Delivery $delivery): JsonResponse
    {
        $data = $request->validate(['state' => ['required', Rule::in(config('delivery.states'))]]);

        return response()->json(['rates' => $delivery->options($data['state'])->map(fn ($rate) => $rate->only('id', 'name', 'fee', 'pay_on_delivery', 'estimate'))->values()]);
    }
}
