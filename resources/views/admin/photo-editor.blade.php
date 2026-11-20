<dialog class="photo-editor" id="photo-editor" aria-labelledby="photo-editor-title" aria-describedby="photo-editor-help">
    <div class="photo-editor-heading">
        <h2 id="photo-editor-title">Crop and edit photo</h2>
        <button type="button" class="photo-editor-close" data-crop-cancel aria-label="Cancel photo editing">×</button>
    </div>
    <p id="photo-editor-help">Drag the photo to position it. The frame shows what will be uploaded.</p>
    <div class="photo-editor-stage" data-crop-stage>
        <div class="photo-editor-frame" data-crop-frame>
            <canvas data-crop-canvas tabindex="0" role="img" aria-label="Crop preview. Use arrow keys to move the photo."></canvas>
            <div class="photo-editor-grid" aria-hidden="true"></div>
        </div>
    </div>
    <p class="photo-editor-status" data-crop-status role="status">Opening photo…</p>
    <div class="photo-editor-settings">
        <label for="photo-editor-ratio">Crop shape
            <select id="photo-editor-ratio" data-crop-ratio>
                <option value="original">Original proportions</option>
                <option value="1">Square · 1:1</option>
                <option value="0.8">Portrait · 4:5</option>
                <option value="1.5">Landscape · 3:2</option>
                <option value="1.7777777778">Wide · 16:9</option>
            </select>
        </label>
        <label for="photo-editor-zoom">Zoom <output for="photo-editor-zoom" data-crop-zoom-value aria-hidden="true">100%</output>
            <input id="photo-editor-zoom" type="range" min="1" max="4" step="0.01" value="1" aria-valuetext="100%" data-crop-zoom>
        </label>
        <label for="photo-editor-brightness">Brightness
            <input id="photo-editor-brightness" type="range" min="0.75" max="1.25" step="0.01" value="1" data-crop-brightness>
        </label>
    </div>
    <div class="photo-editor-tools" role="group" aria-label="Photo adjustments">
        <button type="button" data-crop-rotate="-90">Rotate left</button>
        <button type="button" data-crop-rotate="90">Rotate right</button>
        <button type="button" data-crop-flip aria-pressed="false">Flip</button>
        <button type="button" data-crop-reset>Reset</button>
    </div>
    <div class="photo-editor-footer">
        <button type="button" class="text-button" data-crop-cancel>Cancel</button>
        <button type="button" class="cta" data-crop-apply disabled>USE THIS PHOTO</button>
    </div>
</dialog>
