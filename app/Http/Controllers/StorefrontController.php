<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Models\Product;

class StorefrontController extends Controller
{
    public function index(): View
    {
        return view('storefront', ['products' => Product::where('active', true)->get()->toArray(), 'sections' => \App\Models\HomepageSection::where('active', true)->orderBy('sort_order')->orderBy('id')->get(), 'settings' => \App\Models\StoreSetting::find(1)?->content ?? []]);
    }

    public function cart(Request $request): JsonResponse
    {
        $quantities = $request->session()->get('cart', []);
        $lines = collect(Product::withTrashed()->whereIn('id', array_keys($quantities))->get()->toArray())->filter(fn ($product) => isset($quantities[$product['id']]))
            ->map(fn ($product) => [...$product, 'quantity' => $quantities[$product['id']]])->values();

        return response()->json([
            'items' => $lines,
            'count' => $lines->sum('quantity'),
            'total' => $lines->sum(fn ($product) => $product['price'] * $product['quantity']),
        ]);
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('products', 'id')->where('active', true)->whereNull('deleted_at')]]);
        $cart = $request->session()->get('cart', []);
        $id = $data['product_id'];
        if (($cart[$id] ?? 0) >= min(99, Product::findOrFail($id)->stock)) {
            return response()->json(['message' => 'The requested quantity is not available (maximum 99 per piece).'], 422);
        }
        $cart[$id] = ($cart[$id] ?? 0) + 1;
        $request->session()->put('cart', $cart);

        return $this->cart($request);
    }

    public function remove(Request $request, int $product): JsonResponse
    {
        $cart = $request->session()->get('cart', []);
        if (isset($cart[$product]) && --$cart[$product] <= 0) {
            unset($cart[$product]);
        }
        $request->session()->put('cart', $cart);

        return $this->cart($request);
    }
}
