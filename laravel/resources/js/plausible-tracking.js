// Safety-wrapped tracking function using Plausible's API format
// All calls are non-blocking - Plausible uses async loading and fire-and-forget requests
window.voltikkaTrack = function(eventName, props = null) {
    if (typeof window.plausible === 'function') {
        if (props) {
            window.plausible(eventName, { props: props });
        } else {
            window.plausible(eventName);
        }
    }
};

// Alpine.js magic helper for Blade templates
// Usage: @click="$track('Event Name')" or @click="$track('Event Name', {prop: 'value'})"
document.addEventListener('alpine:init', () => {
    Alpine.magic('track', () => (eventName, props = null) => {
        voltikkaTrack(eventName, props);
    });
});

// Livewire event listener for PHP-dispatched tracking
// Usage in PHP: $this->dispatch('track', event: 'Event Name', props: ['key' => 'value'])
document.addEventListener('livewire:init', () => {
    Livewire.on('track', (data) => {
        voltikkaTrack(data[0].event, data[0].props || null);
    });
});
