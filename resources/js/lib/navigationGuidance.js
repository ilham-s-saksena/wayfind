export const NAVIGATION_SETTINGS = Object.freeze({
    maximumAccuracy: 35,
    arrivalAccuracy: 20,
    arrivalDistance: 12,
    arrivalConfirmations: 3,
    arrivalDuration: 5000,
    maximumPositionAge: 30000,
    walkingSpeed: 1.25,
});

export const confirmArrival = (previous, { remainingDistance, destinationDistance, progress, accuracy, timestamp }, now = Date.now()) => {
    const empty = { count: 0, startedAt: null, timestamp: null, arrived: false };
    const settings = NAVIGATION_SETTINGS;
    if (!Number.isFinite(accuracy) || accuracy <= 0 || accuracy > settings.arrivalAccuracy
        || !Number.isFinite(timestamp) || now - timestamp > settings.maximumPositionAge || timestamp > now + 1000
        || !Number.isFinite(remainingDistance) || !Number.isFinite(destinationDistance) || !Number.isFinite(progress)
        || remainingDistance > settings.arrivalDistance || destinationDistance > settings.arrivalDistance || progress < 0.9) {
        return empty;
    }
    if (previous?.timestamp && timestamp <= previous.timestamp) return previous;
    if (previous?.timestamp && timestamp - previous.timestamp > settings.maximumPositionAge) previous = null;
    const startedAt = previous?.startedAt ?? timestamp;
    const count = (previous?.count ?? 0) + 1;
    return { count, startedAt, timestamp, arrived: count >= settings.arrivalConfirmations && timestamp - startedAt >= settings.arrivalDuration };
};

export const formatDistance = (meters) => meters >= 1000
    ? `${(meters / 1000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} km`
    : `${Math.max(0, Math.round(meters))} m`;
