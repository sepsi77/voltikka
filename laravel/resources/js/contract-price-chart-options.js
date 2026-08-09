export function latestNonNullPointIndices(u, seriesIdx) {
    const values = u.data[seriesIdx] || [];

    for (let index = values.length - 1; index >= 0; index -= 1) {
        if (values[index] !== null && values[index] !== undefined && !Number.isNaN(values[index])) {
            return [index];
        }
    }

    return [];
}

export function logicalSeriesPointOptions(showPoints, stroke, showLatestPoint = false) {
    if (showPoints !== true && showLatestPoint !== true) {
        return { show: false };
    }

    return {
        show: true,
        size: 4,
        width: 1.25,
        stroke,
        fill: '#ffffff',
        ...(showLatestPoint === true ? { filter: latestNonNullPointIndices } : {}),
    };
}
