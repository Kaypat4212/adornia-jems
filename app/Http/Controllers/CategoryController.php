<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('admin.categories', [
            'categories' => Category::withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function save(Request $request, ?Category $category = null): RedirectResponse
    {
        $category ??= new Category;
        $data = $request->validate([
            'name' => ['bail', 'required', 'string', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail) use ($category): void {
                    if (is_string($value) && Category::whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
                        ->whereKeyNot($category->id ?? 0)->exists()) {
                        $fail('A category with this name already exists.');
                    }
                },
            ],
        ]);

        try {
            $category->fill($data)->save();
        } catch (QueryException $exception) {
            if (! str_starts_with((string) $exception->getCode(), '23')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['name' => 'A category with this name already exists.']);
        }

        return redirect()->route('admin.categories')->with('status', 'Category saved.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->withErrors(['category' => 'Move all products in this category, including archived products, to another category before deleting it.']);
        }

        try {
            $category->delete();
        } catch (QueryException $exception) {
            if (! str_starts_with((string) $exception->getCode(), '23')) {
                throw $exception;
            }

            return back()->withErrors(['category' => 'This category is in use. Move its products before deleting it.']);
        }

        return redirect()->route('admin.categories')->with('status', 'Category deleted.');
    }
}
