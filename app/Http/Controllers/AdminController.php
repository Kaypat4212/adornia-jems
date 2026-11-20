<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('admin.index', ['products' => Product::latest()->paginate(15), 'orders' => Order::latest()->paginate(15, ['*'], 'orders_page'), 'revenue' => Order::where('payment_status', 'paid')->where('payment_mode', 'live')->sum('total'), 'pending' => Order::where('status', 'pending')->count(), 'lowStock' => Product::where('stock', '<=', 5)->count()]);
    }

    public function create(): View
    {
        return view('admin.product', [
            'product' => new Product(['active' => true, 'stock' => 0, 'art' => 'ring']),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function edit(Product $product): View
    {
        return view('admin.product', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Product::create($this->data($request));

        return redirect('/admin')->with('status', 'Product created.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $product->update($this->data($request));

        return redirect('/admin')->with('status', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return back()->with('status', 'Product removed from the store. Existing order history is preserved.');
    }

    private function data(Request $request): array
    {
        $upload = $request->file('image_upload');
        $uploadError = $upload instanceof \Illuminate\Http\UploadedFile ? $upload->getErrorMessage() : '';
        $data = $request->validate(['name' => 'required|string|max:100', 'type' => 'required|string|exists:categories,name', 'detail' => 'required|string|max:200', 'description' => 'nullable|string|max:5000', 'price' => 'required|integer|min:1|max:100000000', 'stock' => 'required|integer|min:0|max:100000', 'art' => 'required|in:ring,band,hoops,necklace', 'tag' => 'nullable|string|max:40', 'active' => 'nullable|boolean', 'image_upload' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096'], [
            'image_upload.uploaded' => $uploadError ?: 'The photo could not be uploaded. Choose it again and retry.',
            'image_upload.max' => 'Choose a photo up to 4 MB.',
            'image_upload.image' => 'Choose a valid JPG, PNG or WebP photo.',
            'image_upload.mimes' => 'Choose a JPG, PNG or WebP photo.',
        ]);
        unset($data['image_upload']);
        $data['tag'] = $data['tag'] ?? '';
        $data['active'] = $request->boolean('active');
        if ($request->hasFile('image_upload')) {
            $data['image'] = $request->file('image_upload')->store('products', 'public');
            if ($data['image'] === false) {
                throw ValidationException::withMessages(['image_upload' => 'The photo could not be saved. Check that the server can write to storage/app/public and that the hosting account has free disk space.']);
            }
        }

        return $data;
    }
}
