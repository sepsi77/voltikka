import './bootstrap';
import './plausible-tracking';
import { createAttributionManager } from './attribution';
import { createContractOrderClickTracker } from './first-party-analytics';

const attributionManager = createAttributionManager(window);
const contractOrderClickTracker = createContractOrderClickTracker({
    windowObject: window,
    attributionManager,
});

attributionManager.refresh();

window.voltikkaAnalytics = {
    trackContractOrderClick: contractOrderClickTracker.trackContractOrderClick,
};

document.addEventListener('livewire:navigated', () => {
    attributionManager.refresh();
});
