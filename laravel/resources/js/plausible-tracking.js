// Safety-wrapped tracking function using Plausible's API format
// All calls are non-blocking - Plausible uses async loading and fire-and-forget requests
window.voltikkaTrack = function(eventName, options = {}) {
    if (typeof window.plausible === 'function') {
        window.plausible(eventName, options);
    }
};

// Alpine.js magic helper for Blade templates
// Usage: @click="$track('Event Name')" or @click="$track('Event Name', { props: { prop: 'value' } })"
document.addEventListener('alpine:init', () => {
    Alpine.magic('track', () => (eventName, options = {}) => {
        voltikkaTrack(eventName, options);
    });
});

// Livewire event listener for PHP-dispatched tracking
// Usage in PHP: $this->dispatch('track', eventName: 'Event Name', options: ['props' => ['key' => 'value']])
document.addEventListener('livewire:init', () => {
    Livewire.on('track', (data) => {
        const payload = data[0] || {};
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

        voltikkaTrack('Engaged', {
            interactive: true,
        });
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
