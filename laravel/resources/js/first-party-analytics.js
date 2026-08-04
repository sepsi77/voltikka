const ANALYTICS_ENDPOINT = '/api/analytics/events';

function fallbackUuid(cryptoObject) {
    const bytes = new Uint8Array(16);

    if (typeof cryptoObject?.getRandomValues === 'function') {
        cryptoObject.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index += 1) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');

    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}

export function eventUuid(cryptoObject) {
    try {
        if (typeof cryptoObject?.randomUUID === 'function') {
            return cryptoObject.randomUUID();
        }
    } catch {
        // Use the local UUID v4 fallback.
    }

    return fallbackUuid(cryptoObject);
}

export function createContractOrderClickTracker({
    windowObject,
    attributionManager,
    endpoint = ANALYTICS_ENDPOINT,
}) {
    function fetchFallback(body) {
        try {
            const request = windowObject.fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body,
                keepalive: true,
                credentials: 'same-origin',
            });

            if (request && typeof request.catch === 'function') {
                request.catch(() => {});
            }
        } catch {
            // Analytics failure must not affect the seller link.
        }
    }

    function send(payload) {
        let body;

        try {
            body = JSON.stringify(payload);
        } catch {
            return;
        }

        try {
            if (typeof windowObject.navigator?.sendBeacon === 'function') {
                const blob = new windowObject.Blob([body], { type: 'application/json' });

                if (windowObject.navigator.sendBeacon(endpoint, blob)) {
                    return;
                }
            }
        } catch {
            // Use keepalive fetch below.
        }

        fetchFallback(body);
    }

    function trackContractOrderClick({ context, placement }) {
        try {
            send({
                event_name: 'contract_order_click',
                event_uuid: eventUuid(windowObject.crypto),
                context,
                attribution: attributionManager.eventAttribution(),
                page_path: windowObject.location.pathname,
                placement,
            });
        } catch {
            // No analytics error can cancel the anchor's normal activation.
        }
    }

    return { trackContractOrderClick };
}
