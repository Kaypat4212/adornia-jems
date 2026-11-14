(() => {
    const progress = document.createElement('div');
    progress.className = 'navigation-progress';
    progress.setAttribute('aria-hidden', 'true');
    const status = document.createElement('div');
    status.className = 'sr-only';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    document.body.append(progress, status);

    const reset = () => {
        document.body.classList.remove('is-navigating');
        document.querySelectorAll('[data-was-busy]').forEach(button => {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.removeAttribute('data-was-busy');
        });
        document.querySelectorAll('[aria-busy="true"]').forEach(form => form.removeAttribute('aria-busy'));
        status.textContent = '';
    };
    window.addEventListener('pageshow', reset);
    document.addEventListener('submit', event => {
        if (event.defaultPrevented || !event.target.checkValidity()) return;
        const button = event.submitter;
        event.target.setAttribute('aria-busy', 'true');
        if (button) {
            button.classList.add('is-loading');
            setTimeout(() => { button.disabled = true; button.dataset.wasBusy = 'true'; }, 0);
        }
        document.body.classList.add('is-navigating');
        status.textContent = button?.hasAttribute('data-verify-bank') ? 'Verifying your account with Paystack. Please wait.' : 'Saving your changes. Please wait.';
        setTimeout(reset, 30000);
    });
    document.addEventListener('click', async event => {
        const copy = event.target.closest('[data-copy]');
        if (copy) {
            try {
                await navigator.clipboard.writeText(copy.dataset.copy);
                copy.textContent = 'Copied ✓';
                status.textContent = 'Account number copied.';
            } catch { status.textContent = 'Select the account number and copy it manually.'; }
        }
        const link = event.target.closest('a');
        if (link && !event.defaultPrevented && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !link.hasAttribute('download') && !link.target && !link.href.includes('/receipt') && !link.hash && new URL(link.href).origin === location.origin) {
            document.body.classList.add('is-navigating');
            setTimeout(reset, 15000);
        }
    });
    const avatarInput = document.querySelector('[data-avatar-input]');
    let previewUrl;
    avatarInput?.addEventListener('change', () => {
        const preview = document.querySelector('[data-avatar-preview]');
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        if (!avatarInput.files[0]) { preview.hidden = true; return; }
        previewUrl = URL.createObjectURL(avatarInput.files[0]);
        preview.src = previewUrl;
        preview.hidden = false;
    });
    const select = document.querySelector('[data-bank-select]');
    const bankStatus = document.querySelector('[data-bank-status]');
    const retry = document.querySelector('[data-banks-retry]');
    async function loadBanks() {
        select.disabled = true;
        const verifyButton = document.querySelector('[data-verify-bank]');
        if (verifyButton) verifyButton.disabled = true;
        retry.hidden = true;
        bankStatus.textContent = 'Fetching banks securely…';
        try {
            const response = await fetch('/account/banks', {headers:{Accept:'application/json'}, signal:AbortSignal.timeout(20000)});
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Banks could not be loaded.');
            select.replaceChildren(new Option('Select your bank', ''));
            result.banks.forEach(bank => select.add(new Option(bank.name, bank.code)));
            select.disabled = false;
            if (verifyButton) verifyButton.disabled = false;
            bankStatus.textContent = result.mode === 'test' ? 'Paystack is in test mode. Saved accounts will be marked as test verifications.' : 'Your account name will be verified by Paystack before it is saved.';
        } catch (error) {
            bankStatus.textContent = error.name === 'TimeoutError' ? 'Loading timed out. Please retry.' : error.message;
            retry.hidden = false;
        }
    }
    if (select) { loadBanks(); retry.addEventListener('click', loadBanks); }
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => entries.forEach(entry => {
            if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); }
        }), {threshold:0.05});
        document.querySelectorAll('.panel, .story, .stats, .hero-copy').forEach(element => {
            element.classList.add('reveal'); observer.observe(element);
        });
    }
})();
