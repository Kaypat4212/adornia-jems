<div class="photo-upload" data-photo-upload data-photo-ratio="{{ $photoRatio ?? '1' }}">
    <label>Photo (JPG, PNG or WebP, up to 8 MB after editing)
        <input type="file" name="image_upload" accept="image/jpeg,image/png,image/webp" data-photo-input>
    </label>
    <p class="small-text" data-photo-upload-status role="status">Choose a photo to crop and preview before saving.</p>
    <div class="photo-upload-selection" data-photo-selection hidden>
        <img class="photo-upload-preview" data-photo-result alt="Photo ready to upload">
        <div class="photo-upload-actions">
            <button type="button" class="text-button" data-photo-edit>Edit crop</button>
            <button type="button" class="text-button" data-photo-clear>Clear selected photo</button>
        </div>
    </div>
</div>
@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('photo-editor.css') }}?v={{ filemtime(public_path('photo-editor.css')) }}">
    @endpush
    @push('dialogs')
        @include('admin.photo-editor')
    @endpush
    @push('scripts')
        <script src="{{ asset('photo-editor.js') }}?v={{ filemtime(public_path('photo-editor.js')) }}" defer></script>
    @endpush
@endonce
