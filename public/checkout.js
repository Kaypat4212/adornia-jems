(() => {
    const checkout = document.querySelector('[data-checkout]');
    if (!checkout) return;
    const state = checkout.querySelector('[data-delivery-state]');
    const rate = checkout.querySelector('[data-delivery-rate]');
    const method = checkout.querySelector('[data-payment-method]');
    const cod = checkout.querySelector('[data-cod-option]');
    const message = checkout.querySelector('[data-delivery-message]');
    const submit = checkout.querySelector('[data-place-order]');
    const fee = checkout.querySelector('[data-delivery-fee]');
    const total = checkout.querySelector('[data-checkout-total]');
    const format = amount => '₦' + Number(amount).toLocaleString('en-NG');
    let rates = [], controller;
    function paymentHint() {
        checkout.querySelector('[data-manual-instructions]').hidden = method.value !== 'manual';
    }
    function update() {
        const selected = rates.find(item => String(item.id) === rate.value);
        cod.disabled = !selected?.pay_on_delivery;
        cod.hidden = cod.disabled;
        if (cod.disabled && method.value === 'cash_on_delivery') method.value = 'wallet';
        fee.textContent = selected ? format(selected.fee) : 'Select location';
        total.textContent = selected ? format(Number(checkout.dataset.subtotal) + selected.fee) : '—';
        submit.disabled = !selected;
        if (selected) message.textContent = [selected.estimate, selected.pay_on_delivery ? 'Pay on delivery available in this zone.' : 'Prepayment required for this zone.'].filter(Boolean).join(' · ');
        paymentHint();
    }
    async function load() {
        controller?.abort();
        controller = new AbortController();
        rates = [];
        rate.disabled = true;
        rate.replaceChildren(new Option(state.value ? 'Loading delivery options…' : 'Choose a state first', ''));
        update();
        if (!state.value) { message.textContent = 'Select your state to see delivery prices and payment options.'; return; }
        const selectedState = state.value;
        message.textContent = 'Fetching delivery prices…';
        try {
            const response = await fetch(checkout.dataset.deliveryUrl + '?state=' + encodeURIComponent(selectedState), {headers:{Accept:'application/json'}, signal:controller.signal});
            if (!response.ok) throw new Error('Delivery prices could not be loaded. Choose your state again to retry.');
            const data = await response.json();
            if (selectedState !== state.value) return;
            rates = data.rates;
            rate.replaceChildren(new Option('Select delivery area / service', ''));
            rates.forEach(item => rate.add(new Option(item.name + ' · ' + format(item.fee), String(item.id))));
            rate.disabled = !rates.length;
            if (rates.some(item => String(item.id) === rate.dataset.selected)) rate.value = rate.dataset.selected;
            else if (rates.length === 1) rate.value = String(rates[0].id);
            rate.dataset.selected = '';
            update();
            if (!rates.length) message.textContent = 'Delivery to this state has not been configured yet. Please contact the store before ordering.';
        } catch (error) {
            if (error.name !== 'AbortError') message.textContent = error.message;
        }
    }
    state.addEventListener('change', load);
    rate.addEventListener('change', update);
    method.addEventListener('change', paymentHint);
    load();
})();
