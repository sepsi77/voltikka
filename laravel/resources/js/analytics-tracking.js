// Analytics events go to the Google Tag Manager data layer as one fixed GTM event,
// `voltikka_event`, with `event_name` and `event_props`. GTM forwards them to Plausible.
// The data layer queues pushes until GTM loads; without GTM nothing reads them.
window.voltikkaTrack = function(eventName, options = {}) {
    const dataLayer = (window.dataLayer = window.dataLayer || []);

    // GTM merges nested objects across pushes; clear the old props so no key leaks into this event.
    dataLayer.push({ event_props: null });
    dataLayer.push({
        event: 'voltikka_event',
        event_name: eventName,
        event_props: { ...(options.props || {}) },
    });
};

// Alpine.js magic helper for Blade templates
// Usage: @click="$track('Event Name')" or @click="$track('Event Name', { props: { prop: 'value' } })"
document.addEventListener('alpine:init', () => {
    Alpine.magic('track', () => (eventName, options = {}) => {
        voltikkaTrack(eventName, options);
    });
});

// Livewire event listener for PHP-dispatched tracking
// Usage in PHP: $this->dispatch('track', eventName: 'Event Name', props: ['key' => 'value'])
document.addEventListener('livewire:init', () => {
    Livewire.on('track', (data) => {
        const payload = (Array.isArray(data) ? data[0] : data) || {};
        const options = payload.options || (payload.props ? { props: payload.props } : {});

        if (payload.eventName) {
            voltikkaTrack(payload.eventName, options);
        }
    });
});

const ENGAGED_AFTER_MS = 30_000;

function createEngagedTracker() {
    let accumulatedVisibleMs = 0;
    let visibleSince = null;
    let timeoutId = null;
    let engagedTracked = false;

    function getCurrentVisibleMs() {
        if (visibleSince === null) {
            return accumulatedVisibleMs;
        }

        return accumulatedVisibleMs + (Date.now() - visibleSince);
    }

    function clearScheduledTimeout() {
        if (timeoutId !== null) {
            clearTimeout(timeoutId);
            timeoutId = null;
        }
    }

    function trackIfReady() {
        if (engagedTracked || getCurrentVisibleMs() < ENGAGED_AFTER_MS) {
            return;
        }

        engagedTracked = true;
        clearScheduledTimeout();

        voltikkaTrack('Engaged');
    }

    function scheduleTimeout() {
        if (engagedTracked || document.hidden) {
            return;
        }

        clearScheduledTimeout();

        const remainingMs = ENGAGED_AFTER_MS - getCurrentVisibleMs();

        if (remainingMs <= 0) {
            trackIfReady();
            return;
        }

        timeoutId = window.setTimeout(() => {
            trackIfReady();
        }, remainingMs);
    }

    function handleVisible() {
        if (engagedTracked || visibleSince !== null || document.hidden) {
            return;
        }

        visibleSince = Date.now();
        scheduleTimeout();
    }

    function handleHidden() {
        if (visibleSince === null) {
            return;
        }

        accumulatedVisibleMs += Date.now() - visibleSince;
        visibleSince = null;

        clearScheduledTimeout();
        trackIfReady();
    }

    function handleVisibilityChange() {
        if (document.hidden) {
            handleHidden();
        } else {
            handleVisible();
        }
    }

    function start() {
        if (document.hidden) {
            return;
        }

        visibleSince = Date.now();
        scheduleTimeout();
    }

    function destroy() {
        clearScheduledTimeout();
        document.removeEventListener('visibilitychange', handleVisibilityChange);
        window.removeEventListener('pagehide', handleHidden);
    }

    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('pagehide', handleHidden);

    start();

    return destroy;
}

let destroyEngagedTracker = createEngagedTracker();

document.addEventListener('livewire:navigated', () => {
    if (typeof destroyEngagedTracker === 'function') {
        destroyEngagedTracker();
    }

    destroyEngagedTracker = createEngagedTracker();
});
