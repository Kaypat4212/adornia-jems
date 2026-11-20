(() => {
    const uploads = document.querySelectorAll('[data-photo-upload]');
    if (!uploads.length) return;

    const dialog = document.querySelector('#photo-editor');
    const stage = dialog.querySelector('[data-crop-stage]');
    const frame = dialog.querySelector('[data-crop-frame]');
    const canvas = dialog.querySelector('[data-crop-canvas]');
    const status = dialog.querySelector('[data-crop-status]');
    const ratioControl = dialog.querySelector('[data-crop-ratio]');
    const zoomControl = dialog.querySelector('[data-crop-zoom]');
    const zoomValue = dialog.querySelector('[data-crop-zoom-value]');
    const brightnessControl = dialog.querySelector('[data-crop-brightness]');
    const flipControl = dialog.querySelector('[data-crop-flip]');
    const applyButton = dialog.querySelector('[data-crop-apply]');
    const pointers = new Map();
    let active;
    let source;
    let sourceFile;
    let sourceUrl;
    let settings;
    let ready = false;
    let version = 0;
    let width = 1;
    let height = 1;
    let gesture;

    function defaults(state) {
        return {ratio: state.root.dataset.photoRatio || '1', zoom: 1, angle: 0, flip: false, brightness: 1, x: 0, y: 0};
    }

    function dimensions() {
        const sideways = settings.angle % 180 !== 0;
        const sourceWidth = sideways ? source.naturalHeight : source.naturalWidth;
        const sourceHeight = sideways ? source.naturalWidth : source.naturalHeight;
        const ratio = settings.ratio === 'original' ? sourceWidth / sourceHeight : Number(settings.ratio);
        return {sourceWidth, sourceHeight, ratio};
    }

    function paint(target, targetWidth, targetHeight, density = 1) {
        const context = target.getContext('2d');
        const {sourceWidth, sourceHeight} = dimensions();
        const scale = Math.max(targetWidth / sourceWidth, targetHeight / sourceHeight) * settings.zoom;
        const limitX = Math.max(0, (sourceWidth * scale - targetWidth) / 2);
        const limitY = Math.max(0, (sourceHeight * scale - targetHeight) / 2);
        settings.x = Math.max(-limitX / targetWidth, Math.min(limitX / targetWidth, settings.x));
        settings.y = Math.max(-limitY / targetHeight, Math.min(limitY / targetHeight, settings.y));

        context.setTransform(density, 0, 0, density, 0, 0);
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, targetWidth, targetHeight);
        context.save();
        context.translate(targetWidth / 2 + settings.x * targetWidth, targetHeight / 2 + settings.y * targetHeight);
        context.rotate(settings.angle * Math.PI / 180);
        context.scale(settings.flip ? -scale : scale, scale);
        context.imageSmoothingQuality = 'high';
        context.drawImage(source, -source.naturalWidth / 2, -source.naturalHeight / 2);
        context.restore();

        if (settings.brightness !== 1) {
            const pixels = context.getImageData(0, 0, target.width, target.height);
            for (let index = 0; index < pixels.data.length; index += 4) {
                pixels.data[index] *= settings.brightness;
                pixels.data[index + 1] *= settings.brightness;
                pixels.data[index + 2] *= settings.brightness;
            }
            context.putImageData(pixels, 0, 0);
        }
    }

    function render() {
        if (!ready || !dialog.open) return;
        const {ratio} = dimensions();
        width = Math.max(1, Math.min(stage.clientWidth - 24, (stage.clientHeight - 24) * ratio));
        height = width / ratio;
        const density = Math.min(window.devicePixelRatio || 1, 2);
        frame.style.width = `${width}px`;
        frame.style.height = `${height}px`;
        canvas.style.width = `${width}px`;
        canvas.style.height = `${height}px`;
        canvas.width = Math.round(width * density);
        canvas.height = Math.round(height * density);
        paint(canvas, width, height, density);
        ratioControl.value = settings.ratio;
        zoomControl.value = settings.zoom;
        zoomValue.textContent = `${Math.round(settings.zoom * 100)}%`;
        zoomControl.setAttribute('aria-valuetext', zoomValue.textContent);
        brightnessControl.value = settings.brightness;
        flipControl.setAttribute('aria-pressed', String(settings.flip));
    }

    function enableControls(enabled) {
        dialog.querySelectorAll('button:not([data-crop-cancel]), select, input').forEach(control => {
            control.disabled = !enabled;
        });
    }

    function setFile(state, file) {
        const transfer = new DataTransfer();
        if (file) transfer.items.add(file);
        state.input.files = transfer.files;
    }

    async function open(state, file, savedSettings) {
        active = state;
        sourceFile = file;
        settings = {...(savedSettings || defaults(state))};
        ready = false;
        canvas.width = canvas.width;
        enableControls(false);
        applyButton.textContent = 'USE THIS PHOTO';
        status.textContent = 'Opening photo…';
        dialog.showModal();
        document.documentElement.classList.add('photo-editor-open');
        const currentVersion = ++version;
        sourceUrl = URL.createObjectURL(file);
        const photo = new Image();
        photo.src = sourceUrl;

        try {
            await photo.decode();
            if (currentVersion !== version) return;
            source = photo;
            ready = true;
            enableControls(true);
            status.textContent = 'Drag or pinch to adjust the crop. Arrow keys move the photo.';
            render();
        } catch {
            if (currentVersion !== version) return;
            status.textContent = 'This photo could not be opened. Choose a JPG, PNG or WebP photo and try again.';
        }
    }

    function clearSelection(state) {
        state.file = null;
        state.original = null;
        state.savedSettings = null;
        state.input.value = '';
        if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
        state.previewUrl = null;
        state.result.removeAttribute('src');
        state.selection.hidden = true;
        state.status.textContent = 'Choose a photo to crop and preview before saving.';
    }

    uploads.forEach(root => {
        const state = {
            root,
            input: root.querySelector('[data-photo-input]'),
            result: root.querySelector('[data-photo-result]'),
            selection: root.querySelector('[data-photo-selection]'),
            status: root.querySelector('[data-photo-upload-status]'),
            edit: root.querySelector('[data-photo-edit]'),
            file: null,
            original: null,
            savedSettings: null,
            previewUrl: null,
        };

        state.input.addEventListener('change', () => {
            const file = state.input.files[0];
            try {
                setFile(state, state.file);
            } catch {
                state.input.value = '';
                state.status.textContent = 'Photo editing requires a current version of Safari, Chrome, Firefox or Edge.';
                return;
            }
            if (!file) return;
            open(state, file);
        });
        state.edit.addEventListener('click', () => open(state, state.original, state.savedSettings));
        root.querySelector('[data-photo-clear]').addEventListener('click', () => clearSelection(state));
        state.input.form.addEventListener('reset', () => clearSelection(state));
        state.input.form.addEventListener('submit', event => {
            if (active?.input.form === state.input.form && dialog.open) {
                event.preventDefault();
                status.textContent = 'Use this photo or cancel editing before saving the form.';
            }
        }, true);
    });

    dialog.querySelectorAll('[data-crop-cancel]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => {
        version++;
        ready = false;
        pointers.clear();
        gesture = null;
        if (sourceUrl) URL.revokeObjectURL(sourceUrl);
        sourceUrl = null;
        source = null;
        sourceFile = null;
        document.documentElement.classList.remove('photo-editor-open');
        const state = active;
        active = null;
        if (state?.input.isConnected) (state.file ? state.edit : state.input).focus({preventScroll: true});
    });

    function setZoom(value, clientX, clientY) {
        if (!ready) return;
        const rect = canvas.getBoundingClientRect();
        const x = clientX === undefined ? 0 : clientX - rect.left - width / 2;
        const y = clientY === undefined ? 0 : clientY - rect.top - height / 2;
        const next = Math.max(1, Math.min(4, value));
        const ratio = next / settings.zoom;
        settings.x = (x - (x - settings.x * width) * ratio) / width;
        settings.y = (y - (y - settings.y * height) * ratio) / height;
        settings.zoom = next;
        render();
    }

    function resetPosition() {
        settings.zoom = 1;
        settings.x = 0;
        settings.y = 0;
        render();
    }

    ratioControl.addEventListener('change', () => {
        settings.ratio = ratioControl.value;
        resetPosition();
    });
    zoomControl.addEventListener('input', () => setZoom(Number(zoomControl.value)));
    brightnessControl.addEventListener('input', () => {
        settings.brightness = Number(brightnessControl.value);
        render();
    });
    dialog.querySelectorAll('[data-crop-rotate]').forEach(button => {
        button.addEventListener('click', () => {
            settings.angle = (settings.angle + Number(button.dataset.cropRotate) + 360) % 360;
            resetPosition();
        });
    });
    flipControl.addEventListener('click', () => {
        settings.flip = !settings.flip;
        render();
    });
    dialog.querySelector('[data-crop-reset]').addEventListener('click', () => {
        settings = defaults(active);
        render();
    });

    function startGesture() {
        const [first, second] = [...pointers.values()];
        gesture = null;
        if (!first) return;
        gesture = {x: first.x, y: first.y, offsetX: settings.x, offsetY: settings.y, zoom: settings.zoom};
        if (second) {
            gesture.x = (first.x + second.x) / 2;
            gesture.y = (first.y + second.y) / 2;
            gesture.distance = Math.hypot(second.x - first.x, second.y - first.y);
        }
    }

    canvas.addEventListener('pointerdown', event => {
        if (!ready || event.button !== 0) return;
        canvas.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        startGesture();
    });
    canvas.addEventListener('pointermove', event => {
        if (!ready || !pointers.has(event.pointerId) || !gesture) return;
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        const [first, second] = [...pointers.values()];
        if (second && gesture.distance > 0) {
            const rect = canvas.getBoundingClientRect();
            const centerX = rect.left + width / 2;
            const centerY = rect.top + height / 2;
            const midpointX = (first.x + second.x) / 2 - centerX;
            const midpointY = (first.y + second.y) / 2 - centerY;
            const distance = Math.hypot(second.x - first.x, second.y - first.y);
            settings.zoom = Math.max(1, Math.min(4, gesture.zoom * distance / gesture.distance));
            const ratio = settings.zoom / gesture.zoom;
            settings.x = (midpointX - (gesture.x - centerX - gesture.offsetX * width) * ratio) / width;
            settings.y = (midpointY - (gesture.y - centerY - gesture.offsetY * height) * ratio) / height;
        } else {
            settings.x = gesture.offsetX + (first.x - gesture.x) / width;
            settings.y = gesture.offsetY + (first.y - gesture.y) / height;
        }
        render();
    });
    function endPointer(event) {
        pointers.delete(event.pointerId);
        startGesture();
    }
    canvas.addEventListener('pointerup', endPointer);
    canvas.addEventListener('pointercancel', endPointer);
    canvas.addEventListener('lostpointercapture', endPointer);
    canvas.addEventListener('wheel', event => {
        if (!ready) return;
        event.preventDefault();
        setZoom(settings.zoom * Math.exp(-Math.sign(event.deltaY) * .08), event.clientX, event.clientY);
    }, {passive: false});
    canvas.addEventListener('keydown', event => {
        if (!ready) return;
        const moves = {ArrowLeft: [10, 0], ArrowRight: [-10, 0], ArrowUp: [0, 10], ArrowDown: [0, -10]};
        if (moves[event.key]) {
            settings.x += moves[event.key][0] / width;
            settings.y += moves[event.key][1] / height;
            render();
        } else if (event.key === '+' || event.key === '=') {
            setZoom(settings.zoom + .1);
        } else if (event.key === '-') {
            setZoom(settings.zoom - .1);
        } else {
            return;
        }
        event.preventDefault();
    });

    applyButton.addEventListener('click', async () => {
        if (!ready) return;
        ready = false;
        const currentVersion = version;
        enableControls(false);
        applyButton.textContent = 'PREPARING…';
        status.textContent = 'Preparing the cropped photo…';
        const output = document.createElement('canvas');
        const {sourceWidth, sourceHeight, ratio} = dimensions();
        const scale = Math.max(width / sourceWidth, height / sourceHeight) * settings.zoom;
        output.width = Math.max(1, Math.floor(Math.min(width / scale, 3200, 3200 * ratio)));
        output.height = Math.max(1, Math.round(output.width / ratio));

        try {
            paint(output, output.width, output.height);
            let blob;
            for (const quality of [.92, .84, .76, .68]) {
                blob = await new Promise(resolve => output.toBlob(resolve, 'image/jpeg', quality));
                if (currentVersion !== version || !dialog.open) return;
                if (!blob) throw new Error('The edited photo could not be prepared. Try a different photo.');
                if (blob.size <= 8 * 1024 * 1024) break;
            }
            if (blob.size > 8 * 1024 * 1024) throw new Error('This crop is too large. Choose a smaller area and try again.');
            const filename = `${sourceFile.name.replace(/\.[^.]+$/, '')}-cropped.jpg`;
            const file = new File([blob], filename, {type: 'image/jpeg', lastModified: Date.now()});
            setFile(active, file);
            active.file = file;
            active.original = sourceFile;
            active.savedSettings = {...settings};
            if (active.previewUrl) URL.revokeObjectURL(active.previewUrl);
            active.previewUrl = URL.createObjectURL(file);
            active.result.src = active.previewUrl;
            active.selection.hidden = false;
            active.status.textContent = `Ready to upload · ${output.width} × ${output.height} · ${(file.size / (1024 * 1024)).toFixed(2)} MB. Save the form to upload.`;
            dialog.close();
        } catch (error) {
            if (currentVersion !== version) return;
            ready = true;
            status.textContent = error.message || 'The photo could not be prepared. Please retry.';
            applyButton.textContent = 'USE THIS PHOTO';
            enableControls(true);
        }
    });

    window.addEventListener('resize', render);
})();
