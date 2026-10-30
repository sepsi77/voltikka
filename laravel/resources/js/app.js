import './bootstrap';
import './analytics-tracking';
import { createAttributionManager } from './attribution';
import { createContractOrderClickTracker, createEventSender } from './first-party-analytics';
import { createPageFeedback } from './page-feedback';

const attributionManager = createAttributionManager(window);
const contractOrderClickTracker = createContractOrderClickTracker({
    windowObject: window,
    attributionManager,
});

attributionManager.refresh();

window.voltikkaAnalytics = {
    trackContractOrderClick: contractOrderClickTracker.trackContractOrderClick,
};

createPageFeedback({
    windowObject: window,
    attributionManager,
    send: createEventSender({ windowObject: window }),
    track: (eventName, options) => window.voltikkaTrack?.(eventName, options),
}).start();

document.addEventListener('livewire:navigated', () => {
    attributionManager.refresh();
});
