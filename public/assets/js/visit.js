(() => {
    const visitId = window.VISIT_LOG_ID;
    if (!visitId) return;

    const startAt = Date.now();
    let sent = false;

    const sendDuration = () => {
        if (sent) return;
        sent = true;

        const durationSeconds = Math.max(1, Math.round((Date.now() - startAt) / 1000));
        const payload = new URLSearchParams();
        payload.set('id', String(visitId));
        payload.set('duration', String(durationSeconds));

        if (navigator.sendBeacon) {
            navigator.sendBeacon('/visit/leave', payload);
            return;
        }

        fetch('/visit/leave', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString(),
            keepalive: true
        }).catch(() => {});
    };

    window.addEventListener('pagehide', sendDuration);
    window.addEventListener('beforeunload', sendDuration);
})();
