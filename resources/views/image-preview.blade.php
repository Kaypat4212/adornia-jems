<dialog class="image-preview-dialog" id="image-preview" aria-labelledby="image-preview-title" aria-describedby="image-preview-help">
    <div class="image-preview-heading">
        <h2 id="image-preview-title">Photo preview</h2>
        <button type="button" class="image-preview-close" data-preview-close aria-label="Close photo preview" autofocus>×</button>
    </div>
    <div class="image-preview-stage" data-preview-stage tabindex="0" role="group" aria-label="Photo. Zoom in, then drag to move around.">
        <img data-preview-image alt="" draggable="false" hidden>
        <p class="image-preview-message" data-preview-message role="status">Loading photo…</p>
    </div>
    <div class="image-preview-controls" role="group" aria-label="Photo zoom">
        <button type="button" data-preview-out aria-label="Zoom out" disabled>−</button>
        <output data-preview-scale aria-live="polite" aria-label="Zoom level">100%</output>
        <button type="button" data-preview-in aria-label="Zoom in" disabled>+</button>
        <button type="button" data-preview-reset disabled>Reset</button>
    </div>
    <p id="image-preview-help">Pinch or use + and − to zoom. Drag to explore. Use arrow keys to move and Esc to close.</p>
</dialog>
<script src="{{ asset('image-preview.js') }}?v={{ filemtime(public_path('image-preview.js')) }}" defer></script>
