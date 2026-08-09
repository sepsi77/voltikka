export function logicalSeriesPointOptions(showPoints, stroke) {
    if (showPoints !== true) {
        return { show: false };
    }

    return {
        show: true,
        size: 4,
        width: 1.25,
        stroke,
        fill: '#ffffff',
    };
}
