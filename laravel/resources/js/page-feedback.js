// "Löysitkö etsimäsi?" widget (x-page-feedback). Server markup holds every step; this
// module shuffles the choices, moves between steps and sends each answer once.
// See app/Services/Analytics/AGENTS.md ("Page feedback").
import { eventUuid } from './first-party-analytics.js';

export const FEEDBACK_STORAGE_KEY = 'voltikka_feedback_v1';
export const ANSWERED_DAYS = 30;
export const DISMISSED_DAYS = 7;

const DAY_MS = 24 * 60 * 60 * 1000;

// Fisher-Yates on a copy. Random order removes the "first option" bias.
export function shuffled(items, random = Math.random) {
    const result = [...items];

    for (let index = result.length - 1; index > 0; index -= 1) {
        const other = Math.floor(random() * (index + 1));
        [result[index], result[other]] = [result[other], result[index]];
    }

    return result;
}

export function isSuppressed(storage, now = Date.now()) {
    try {
        const stored = JSON.parse(storage?.getItem(FEEDBACK_STORAGE_KEY) ?? 'null');

        return typeof stored?.until === 'number' && stored.until > now;
    } catch {
        return false;
    }
}

export function suppress(storage, days, now = Date.now()) {
    try {
        storage?.setItem(FEEDBACK_STORAGE_KEY, JSON.stringify({ until: now + days * DAY_MS }));
    } catch {
        // Blocked storage only means the question can show again.
    }
}

export function feedbackPayload({ root, state, step, answer, attribution, pathname, device }) {
    const payload = {
        event_name: 'page_feedback',
        event_uuid: state.uuid,
        context: root.dataset.feedbackContext,
        attribution,
        page_path: pathname,
        device,
        step,
        ...answer,
    };

    if (step === 'vote') {
        payload.facts = listingFacts(root);
    }

    return payload;
}

// The listing renders its current facts on the list element (Livewire keeps it fresh);
// the widget itself is wire:ignore, so it reads them at send time.
function listingFacts(root) {
    try {
        const raw = root.closest?.('[data-feedback-facts]')?.dataset.feedbackFacts;

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

export function createPageFeedback({ windowObject, attributionManager, send, track }) {
    const documentObject = windowObject.document;
    const states = new WeakMap();

    function storage() {
        try {
            return windowObject.localStorage;
        } catch {
            return null;
        }
    }

    function stateOf(root) {
        if (!states.has(root)) {
            states.set(root, { uuid: null, vote: null, sent: new Set() });
        }

        return states.get(root);
    }

    function device() {
        try {
            return windowObject.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'desktop';
        } catch {
            return 'desktop';
        }
    }

    function prepare(root) {
        if (root.dataset.feedbackPrepared) {
            return;
        }

        root.dataset.feedbackPrepared = '1';

        if (root.dataset.feedbackStart === 'vote' && isSuppressed(storage())) {
            return;
        }

        root.querySelectorAll('[data-feedback-options]').forEach((list) => {
            const items = [...list.children];
            const fixed = items.filter((item) => item.hasAttribute('data-feedback-fixed-last'));
            const movable = items.filter((item) => !item.hasAttribute('data-feedback-fixed-last'));

            [...shuffled(movable), ...fixed].forEach((item) => list.appendChild(item));
        });

        root.hidden = false;
    }

    function prepareAll() {
        documentObject.querySelectorAll('[data-page-feedback]:not([data-feedback-prepared])').forEach(prepare);
    }

    function showStep(root, name) {
        root.querySelectorAll('[data-feedback-step]').forEach((step) => {
            step.hidden = step.dataset.feedbackStep !== name;
        });

        root.querySelector(`[data-feedback-step="${name}"] [data-feedback-focus]`)?.focus({ preventScroll: true });
    }

    function sendStep(root, step, answer) {
        const state = stateOf(root);

        if (state.sent.has(step)) {
            return;
        }

        state.sent.add(step);
        state.uuid ??= eventUuid(windowObject.crypto);

        try {
            send(feedbackPayload({
                root,
                state,
                step,
                answer,
                attribution: attributionManager.eventAttribution(),
                pathname: windowObject.location.pathname,
                device: device(),
            }));
        } catch {
            // The visitor still sees the next step.
        }
    }

    function trackEvent(name, props) {
        try {
            track(name, { props });
        } catch {
            // Plausible is independent of the first-party row.
        }
    }

    function openGoalStep(root) {
        const state = stateOf(root);
        const step = root.querySelector('[data-feedback-step="goal"]');
        const heading = step?.querySelector('[data-feedback-goal-heading]');

        if (heading && state.vote) {
            heading.textContent = state.vote === 'up' ? heading.dataset.headingUp : heading.dataset.headingDown;
        }

        step?.querySelectorAll('[data-feedback-mark]').forEach((mark) => {
            mark.hidden = mark.dataset.feedbackMark !== state.vote;
        });

        const counter = root.querySelector('[data-feedback-counter]');

        if (counter) {
            counter.textContent = state.vote === 'down' && root.querySelector('[data-feedback-step="reason"]')
                ? 'Kysymys 2/2'
                : '';
        }

        showStep(root, 'goal');
    }

    function vote(root, value) {
        const state = stateOf(root);

        if (state.vote) {
            return;
        }

        state.vote = value;
        sendStep(root, 'vote', { vote: value });
        suppress(storage(), ANSWERED_DAYS);
        trackEvent('Feedback Vote', { page_type: root.dataset.feedbackPageType, vote: value });

        const dismiss = root.querySelector('[data-feedback-action="dismiss"] [data-feedback-dismiss-label]');

        if (dismiss) {
            dismiss.textContent = 'Sulje';
        }

        if (value === 'down' && root.querySelector('[data-feedback-step="reason"]')) {
            const counter = root.querySelector('[data-feedback-counter]');

            if (counter) {
                counter.textContent = 'Kysymys 1/2';
            }

            showStep(root, 'reason');

            return;
        }

        openGoalStep(root);
    }

    function submit(root, stepElement) {
        const step = stepElement.dataset.feedbackStep;
        const inputs = [...stepElement.querySelectorAll('input[type="radio"]')];
        const checked = inputs.findIndex((input) => input.checked);
        const text = stepElement.querySelector('textarea')?.value.trim() ?? '';

        if (checked >= 0 || text !== '') {
            const choice = checked >= 0 ? inputs[checked].value : null;

            sendStep(root, step, {
                choice,
                position: checked >= 0 ? checked + 1 : null,
                text: text === '' ? null : text,
            });
            trackEvent(step === 'reason' ? 'Feedback Reason' : 'Feedback Goal', {
                page_type: root.dataset.feedbackPageType,
                [step]: choice ?? 'text_only',
            });
        }

        advance(root, step);
    }

    function advance(root, step) {
        if (step === 'reason') {
            openGoalStep(root);

            return;
        }

        showStep(root, 'thanks');
        root.querySelector('[data-feedback-counter]')?.replaceChildren();
        root.querySelector('[data-feedback-action="dismiss"]')?.setAttribute('hidden', '');
    }

    function dismiss(root) {
        const state = stateOf(root);

        if (state.vote || root.dataset.feedbackStart === 'goal') {
            if (state.vote) {
                advance(root, 'goal');
            } else {
                root.hidden = true;
            }

            return;
        }

        suppress(storage(), DISMISSED_DAYS);
        root.hidden = true;
    }

    function onClick(event) {
        const target = event.target instanceof windowObject.Element ? event.target : null;
        const root = target?.closest('[data-page-feedback]');

        if (!root) {
            return;
        }

        const voteButton = target.closest('[data-feedback-vote]');

        if (voteButton) {
            vote(root, voteButton.dataset.feedbackVote);

            return;
        }

        const action = target.closest('[data-feedback-action]');

        if (!action) {
            return;
        }

        const stepElement = action.closest('[data-feedback-step]');

        if (action.dataset.feedbackAction === 'submit' && stepElement) {
            submit(root, stepElement);
        } else if (action.dataset.feedbackAction === 'skip' && stepElement) {
            advance(root, stepElement.dataset.feedbackStep);
        } else if (action.dataset.feedbackAction === 'dismiss') {
            dismiss(root);
        }
    }

    function onInput(event) {
        const field = event.target;

        if (field?.tagName !== 'TEXTAREA' || !field.closest?.('[data-page-feedback]')) {
            return;
        }

        const count = field.closest('[data-feedback-step]')?.querySelector('[data-feedback-count]');

        if (count) {
            count.textContent = `${field.value.length}/${field.maxLength}`;
        }
    }

    function start() {
        documentObject.addEventListener('click', onClick);
        documentObject.addEventListener('input', onInput);
        prepareAll();

        // A listing can render the widget later (filters change the list), so watch for it.
        let scheduled = false;
        const observer = new windowObject.MutationObserver(() => {
            if (scheduled) {
                return;
            }

            scheduled = true;
            windowObject.requestAnimationFrame(() => {
                scheduled = false;
                prepareAll();
            });
        });

        observer.observe(documentObject.documentElement, { childList: true, subtree: true });
    }

    return { start, prepare };
}
