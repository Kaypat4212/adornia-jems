(() => {
    const dialog = document.querySelector('#image-preview');
    if (!dialog) return;

    const stage = dialog.querySelector('[data-preview-stage]');
    const image = dialog.querySelector('[data-preview-image]');
    const title = dialog.querySelector('#image-preview-title');
    const message = dialog.querySelector('[data-preview-message]');
    const scaleOutput = dialog.querySelector('[data-preview-scale]');
    const zoomIn = dialog.querySelector('[data-preview-in]');
    const zoomOut = dialog.querySelector('[data-preview-out]');
    const resetButton = dialog.querySelector('[data-preview-reset]');
    const pointers = new Map();
    let zoom = 1;
    let offsetX = 0;
    let offsetY = 0;
    let ready = false;
    let opener;
    let gesture;

    function render() {
        const fittedScale = Math.min(stage.clientWidth / image.naturalWidth, stage.clientHeight / image.naturalHeight);
        const limitX = ready ? Math.max(0, (image.naturalWidth * fittedScale * zoom - stage.clientWidth) / 2) : 0;
        const limitY = ready ? Math.max(0, (image.naturalHeight * fittedScale * zoom - stage.clientHeight) / 2) : 0;
        offsetX = Math.max(-limitX, Math.min(limitX, offsetX));
        offsetY = Math.max(-limitY, Math.min(limitY, offsetY));
        image.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${zoom})`;
        const percentage = `${Math.round(zoom * 100)}%`;
        if (scaleOutput.textContent !== percentage) scaleOutput.textContent = percentage;
        zoomIn.disabled = !ready || zoom >= 5;
        zoomOut.disabled = !ready || zoom <= 1;
        resetButton.disabled = !ready;
        stage.classList.toggle('is-zoomed', ready && zoom > 1);
    }

    function reset() {
        zoom = 1;
        offsetX = 0;
        offsetY = 0;
        render();
    }

    function setZoom(value, clientX, clientY) {
        if (!ready) return;
        const rect = stage.getBoundingClientRect();
        const anchorX = clientX === undefined ? 0 : clientX - rect.left - rect.width / 2;
        const anchorY = clientY === undefined ? 0 : clientY - rect.top - rect.height / 2;
        const nextZoom = Math.max(1, Math.min(5, value));
        const ratio = nextZoom / zoom;
        offsetX = anchorX - (anchorX - offsetX) * ratio;
        offsetY = anchorY - (anchorY - offsetY) * ratio;
        zoom = nextZoom;
        render();
    }

    function imageLoaded() {
        if (!dialog.open || image.naturalWidth === 0) return;
        ready = true;
        image.hidden = false;
        message.hidden = true;
        reset();
    }

    image.addEventListener('load', imageLoaded);
    image.addEventListener('error', () => {
        if (!dialog.open) return;
        ready = false;
        image.hidden = true;
        message.textContent = 'This photo could not load. Close the preview and try again.';
        message.hidden = false;
        render();
    });

    document.querySelectorAll('img.preview-image').forEach(photo => {
        const link = document.createElement('a');
        link.href = photo.src;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'admin-image-preview';
        link.dataset.imagePreview = '';
        link.setAttribute('aria-label', `Enlarge ${photo.alt || 'photo'}`);
        link.setAttribute('aria-haspopup', 'dialog');
        photo.replaceWith(link);
        link.append(photo);
    });

    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-image-preview]');
        if (!trigger || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const photo = trigger.querySelector('img');
        const source = trigger.dataset.imageSrc || photo?.currentSrc || photo?.src;
        if (!source) return;
        event.preventDefault();
        opener = trigger;
        ready = false;
        image.hidden = true;
        image.alt = trigger.dataset.imageAlt || photo?.alt || 'Jewelry photo';
        title.textContent = image.alt;
        message.textContent = 'Loading photo…';
        message.hidden = false;
        dialog.showModal();
        document.documentElement.classList.add('image-preview-open');
        reset();
        image.src = source;
        if (image.complete && image.naturalWidth > 0) imageLoaded();
    });

    dialog.querySelector('[data-preview-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => {
        document.documentElement.classList.remove('image-preview-open');
        pointers.clear();
        gesture = null;
        stage.classList.remove('is-interacting');
        if (opener?.isConnected) opener.focus({preventScroll: true});
    });
    zoomIn.addEventListener('click', () => setZoom(zoom + .5));
    zoomOut.addEventListener('click', () => setZoom(zoom - .5));
    resetButton.addEventListener('click', reset);
    stage.addEventListener('dblclick', event => setZoom(zoom > 1 ? 1 : 2.5, event.clientX, event.clientY));
    stage.addEventListener('wheel', event => {
        if (!ready) return;
        event.preventDefault();
        const delta = event.deltaY * (event.deltaMode === 1 ? 16 : 1);
        setZoom(zoom * Math.exp(-Math.max(-100, Math.min(100, delta)) * .003), event.clientX, event.clientY);
    }, {passive: false});

    function startGesture() {
        const [first, second] = [...pointers.values()];
        gesture = null;
        if (!first) return;
        gesture = {x: first.x, y: first.y, offsetX, offsetY, zoom};
        if (second) {
            gesture.distance = Math.hypot(second.x - first.x, second.y - first.y);
            gesture.x = (first.x + second.x) / 2;
            gesture.y = (first.y + second.y) / 2;
        }
    }

    stage.addEventListener('pointerdown', event => {
        if (!ready || event.button !== 0) return;
        stage.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        stage.classList.add('is-interacting');
        startGesture();
    });
    stage.addEventListener('pointermove', event => {
        if (!pointers.has(event.pointerId) || !gesture) return;
        pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
        const [first, second] = [...pointers.values()];
        if (second && gesture.distance > 0) {
            const rect = stage.getBoundingClientRect();
            const distance = Math.hypot(second.x - first.x, second.y - first.y);
            const centerX = rect.left + rect.width / 2;
            const centerY = rect.top + rect.height / 2;
            const midpointX = (first.x + second.x) / 2 - centerX;
            const midpointY = (first.y + second.y) / 2 - centerY;
            zoom = Math.max(1, Math.min(5, gesture.zoom * distance / gesture.distance));
            const ratio = zoom / gesture.zoom;
            offsetX = midpointX - (gesture.x - centerX - gesture.offsetX) * ratio;
            offsetY = midpointY - (gesture.y - centerY - gesture.offsetY) * ratio;
        } else {
            offsetX = gesture.offsetX + first.x - gesture.x;
            offsetY = gesture.offsetY + first.y - gesture.y;
        }
        render();
    });

    function endPointer(event) {
        pointers.delete(event.pointerId);
        stage.classList.toggle('is-interacting', pointers.size > 0);
        startGesture();
    }

    stage.addEventListener('pointerup', endPointer);
    stage.addEventListener('pointercancel', endPointer);
    stage.addEventListener('lostpointercapture', endPointer);
    dialog.addEventListener('keydown', event => {
        if (!ready) return;
        if (['+', '='].includes(event.key)) {
            setZoom(zoom + .5);
        } else if (event.key === '-') {
            setZoom(zoom - .5);
        } else if (event.key === '0') {
            reset();
        } else if (zoom > 1 && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            offsetX += event.key === 'ArrowLeft' ? 50 : event.key === 'ArrowRight' ? -50 : 0;
            offsetY += event.key === 'ArrowUp' ? 50 : event.key === 'ArrowDown' ? -50 : 0;
            render();
        } else {
            return;
        }
        event.preventDefault();
    });
    window.addEventListener('resize', () => {
        if (dialog.open) render();
    });
})();
