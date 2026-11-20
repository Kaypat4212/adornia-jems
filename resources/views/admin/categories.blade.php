@extends('layouts.store')
@section('title', 'Categories')
@section('content')
<h1 class="page-title">Categories</h1>
<form class="panel" method="post" action="{{ route('admin.categories.store') }}">
    @csrf
    <h2>Add a category</h2>
    <label>Category name
        <input name="name" value="{{ old('name') }}" maxlength="100" required>
    </label>
    <button class="cta">ADD CATEGORY</button>
</form>
@forelse($categories as $category)
    <div class="panel">
        <form method="post" action="{{ route('admin.categories.update', $category) }}">
            @csrf
            @method('PUT')
            <label>Category name
                <input name="name" value="{{ $category->name }}" maxlength="100" required>
            </label>
            <p>{{ $category->products_count }} {{ Str::plural('product', $category->products_count) }}, including archived products</p>
            <button class="cta">SAVE CATEGORY</button>
        </form>
        @if($category->products_count === 0)
            <form method="post" action="{{ route('admin.categories.destroy', $category) }}" onsubmit="return confirm('Delete this category?')">
                @csrf
                @method('DELETE')
                <button class="text-button">Delete category</button>
            </form>
        @endif
    </div>
@empty
    <p>Add a category to start organizing your products.</p>
@endforelse
@endsection
