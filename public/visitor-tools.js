(() => {
    const base = document.querySelector('meta[name="store-base"]')?.content.replace(/\/$/, '');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const live = document.querySelector('[data-live-visitors]');
    let stopped = false;

    async function heartbeat() {
        if (!base || !csrf || stopped || document.hidden) return;
        const prefix = new URL(base, location.origin).pathname.replace(/\/$/, '');
        const path = location.pathname.slice(prefix.length) || '/';
        if (!/^\/(?:products\/[0-9]+)?$/.test(path)) return;
        try {
            const response = await fetch(`${base}/visitor/heartbeat`, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({path}), signal: AbortSignal.timeout(10000),
            });
            if (response.status === 403 || response.status === 419) stopped = true;
        } catch {
            // Activity reporting must not interrupt shopping.
        }
    }

    async function refreshLive() {
        if (!live || document.hidden) return;
        try {
            const response = await fetch(live.dataset.liveUrl, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: AbortSignal.timeout(10000)});
            if (!response.ok) throw new Error('Activity could not be refreshed.');
            const data = await response.json();
            document.querySelector('[data-active-visitors]').textContent = data.active;
            const rows = data.visitors.map(visitor => {
                const row = document.createElement('p');
                row.textContent = `Visitor #${visitor.id} · ${visitor.ip} · Country: ${visitor.country || 'Unknown'} · Device type: ${visitor.device || 'Unknown'} · ${visitor.user} · ${visitor.path}`;
                return row;
            });
            if (!rows.length) {
                const row = document.createElement('p');
                row.textContent = 'No visitors active right now.';
                rows.push(row);
            }
            live.replaceChildren(...rows);
        } catch {
            live.textContent = 'Live activity could not be refreshed. Reload to retry.';
        }
    }

    heartbeat();
    refreshLive();
    setInterval(heartbeat, 30000);
    if (live) setInterval(refreshLive, 15000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { heartbeat(); refreshLive(); }
    });
})();
