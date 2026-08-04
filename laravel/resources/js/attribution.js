export const ATTRIBUTION_STORAGE_KEY = 'voltikka_attribution_v1';
export const ATTRIBUTION_VERSION = 1;
export const ATTRIBUTION_TIMEOUT_MS = 30 * 60 * 1000;

const SOURCE_MEDIUM_MAX_LENGTH = 100;
const CAMPAIGN_MAX_LENGTH = 150;
const PATH_MAX_LENGTH = 500;

const SEARCH_ENGINES = [
    { domains: ['bing.com'], source: 'bing' },
    { domains: ['duckduckgo.com'], source: 'duckduckgo' },
    { domains: ['search.yahoo.com', 'yahoo.com'], source: 'yahoo' },
    { domains: ['ecosia.org'], source: 'ecosia' },
    { domains: ['search.brave.com'], source: 'brave' },
];

function normalizeText(value, maximumLength) {
    if (typeof value !== 'string') {
        return '';
    }

    return value
        .replace(/[\u0000-\u001f\u007f]/g, ' ')
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase()
        .slice(0, maximumLength);
}

function normalizedPath(pathname) {
    if (typeof pathname !== 'string' || ! pathname.startsWith('/') || pathname.startsWith('//')) {
        return '/';
    }

    return pathname.split(/[?#]/, 1)[0].slice(0, PATH_MAX_LENGTH) || '/';
}

function normalizedHostname(hostname) {
    return normalizeText(hostname, SOURCE_MEDIUM_MAX_LENGTH)
        .replace(/^www\./, '')
        .replace(/\.$/, '');
}

function hostnameMatches(hostname, domain) {
    return hostname === domain || hostname.endsWith(`.${domain}`);
}

function isGoogleHostname(hostname) {
    return /^google\.(?:[a-z]{2,3}|co\.[a-z]{2}|com\.[a-z]{2})$/.test(hostname);
}

function referrerUrl(windowObject) {
    const referrer = windowObject.document?.referrer;

    if (typeof referrer !== 'string' || referrer.trim() === '') {
        return null;
    }

    try {
        return new URL(referrer, windowObject.location.href);
    } catch {
        return null;
    }
}

function externalReferrer(windowObject) {
    const referrer = referrerUrl(windowObject);

    if (! referrer) {
        return null;
    }

    try {
        return referrer.origin === windowObject.location.origin ? null : referrer;
    } catch {
        return null;
    }
}

function utmAttribution(windowObject) {
    let parameters;

    try {
        parameters = new URLSearchParams(windowObject.location.search || '');
    } catch {
        return null;
    }

    const source = normalizeText(parameters.get('utm_source'), SOURCE_MEDIUM_MAX_LENGTH);

    if (source === '') {
        return null;
    }

    const medium = normalizeText(parameters.get('utm_medium'), SOURCE_MEDIUM_MAX_LENGTH) || '(none)';
    const campaign = normalizeText(parameters.get('utm_campaign'), CAMPAIGN_MAX_LENGTH) || null;

    return { source, medium, campaign };
}

export function resolveAttribution(windowObject) {
    const landingPath = normalizedPath(windowObject.location.pathname);
    const utm = utmAttribution(windowObject);

    if (utm) {
        return { ...utm, landing_path: landingPath };
    }

    const referrer = externalReferrer(windowObject);

    if (! referrer) {
        return {
            source: 'direct',
            medium: '(none)',
            campaign: null,
            landing_path: landingPath,
        };
    }

    const hostname = normalizedHostname(referrer.hostname);
    const searchEngine = SEARCH_ENGINES.find(({ domains }) => (
        domains.some((domain) => hostnameMatches(hostname, domain))
    ));

    if (isGoogleHostname(hostname) || searchEngine) {
        return {
            source: isGoogleHostname(hostname) ? 'google' : searchEngine.source,
            medium: 'organic',
            campaign: null,
            landing_path: landingPath,
        };
    }

    return {
        source: hostname || 'direct',
        medium: hostname ? 'referral' : '(none)',
        campaign: null,
        landing_path: landingPath,
    };
}

function isValidAttribution(value, now) {
    if (! value || typeof value !== 'object' || Array.isArray(value)) {
        return false;
    }

    const keys = Object.keys(value).sort();
    const expectedKeys = [
        'campaign',
        'landing_path',
        'last_activity_at',
        'medium',
        'source',
        'started_at',
        'version',
    ];

    if (keys.length !== expectedKeys.length || keys.some((key, index) => key !== expectedKeys[index])) {
        return false;
    }

    const validCampaign = value.campaign === null
        || (typeof value.campaign === 'string' && value.campaign.length <= CAMPAIGN_MAX_LENGTH);

    return value.version === ATTRIBUTION_VERSION
        && typeof value.source === 'string'
        && value.source.length > 0
        && value.source.length <= SOURCE_MEDIUM_MAX_LENGTH
        && typeof value.medium === 'string'
        && value.medium.length > 0
        && value.medium.length <= SOURCE_MEDIUM_MAX_LENGTH
        && validCampaign
        && typeof value.landing_path === 'string'
        && value.landing_path.startsWith('/')
        && ! value.landing_path.startsWith('//')
        && value.landing_path.length <= PATH_MAX_LENGTH
        && Number.isInteger(value.started_at)
        && value.started_at >= 0
        && Number.isInteger(value.last_activity_at)
        && value.last_activity_at >= value.started_at
        && value.last_activity_at <= now + 5 * 60 * 1000;
}

function isNewCampaign(stored, currentUtm) {
    return currentUtm !== null && (
        stored.source !== currentUtm.source
        || stored.medium !== currentUtm.medium
        || stored.campaign !== currentUtm.campaign
    );
}

export function createAttributionManager(windowObject, { now = () => Date.now() } = {}) {
    let memoryAttribution = null;

    function readStored(currentTime) {
        try {
            const raw = windowObject.localStorage.getItem(ATTRIBUTION_STORAGE_KEY);

            if (raw === null) {
                return isValidAttribution(memoryAttribution, currentTime) ? memoryAttribution : null;
            }

            const parsed = JSON.parse(raw);

            if (isValidAttribution(parsed, currentTime)) {
                memoryAttribution = parsed;

                return parsed;
            }

            try {
                windowObject.localStorage.removeItem(ATTRIBUTION_STORAGE_KEY);
            } catch {
                // A later write will use the current-document memory fallback.
            }

            return null;
        } catch {
            return isValidAttribution(memoryAttribution, currentTime) ? memoryAttribution : null;
        }
    }

    function writeStored(attribution) {
        memoryAttribution = attribution;

        try {
            windowObject.localStorage.setItem(ATTRIBUTION_STORAGE_KEY, JSON.stringify(attribution));
        } catch {
            // The current document continues with memoryAttribution.
        }
    }

    function refresh() {
        const currentTime = Math.trunc(now());
        let attribution = readStored(currentTime);
        const currentUtm = utmAttribution(windowObject);
        const expired = attribution !== null
            && currentTime - attribution.last_activity_at > ATTRIBUTION_TIMEOUT_MS;

        if (attribution === null || expired || isNewCampaign(attribution, currentUtm)) {
            attribution = {
                version: ATTRIBUTION_VERSION,
                ...resolveAttribution(windowObject),
                started_at: currentTime,
                last_activity_at: currentTime,
            };
        } else {
            attribution = {
                ...attribution,
                last_activity_at: currentTime,
            };
        }

        writeStored(attribution);

        return { ...attribution };
    }

    function eventAttribution() {
        const attribution = refresh();

        return {
            source: attribution.source,
            medium: attribution.medium,
            campaign: attribution.campaign,
            landing_path: attribution.landing_path,
        };
    }

    return { refresh, eventAttribution };
}
