const test = require('node:test');
const assert = require('node:assert/strict');
require('../../public/dashboard-assets/pulse-chart.js');
const {seriesModel} = globalThis.GrandmaPulseChart;

test('charging is visible even when no pulse measurements exist', () => {
    const model = seriesModel({from_ms: 0, to_ms: 3600000, gap_ms: 600000,
        points: [], charging: {intervals: [[100000, 900000]]}});
    assert.deepEqual(model.charging, [[100000, 900000]]);
    assert.deepEqual(model.gaps, [[0, 3600000]]);
    assert.deepEqual(model.segments, []);
});

test('charging bands are clipped to the selected period', () => {
    const model = seriesModel({from_ms: 100, to_ms: 200, gap_ms: 10, points: [],
        charging: {intervals: [[0, 120], [170, 300], [300, 400]]}});
    assert.deepEqual(model.charging, [[100, 120], [170, 200]]);
});

test('a short charging session breaks the pulse line without a false long gap', () => {
    const points = [[100000, 73], [400000, 76]];
    const model = seriesModel({from_ms: 0, to_ms: 500000, gap_ms: 600000,
        points, charging: {intervals: [[200000, 300000]]}});
    assert.deepEqual(model.segments, [[points[0]], [points[1]]]);
    assert.deepEqual(model.gaps, []);
    assert.deepEqual(model.segments.flat(), points);
});

test('old responses without charging retain their pulse segments and gaps', () => {
    const points = [[100000, 73], [150000, 76], [900000, 75]];
    const model = seriesModel({from_ms: 0, to_ms: 1000000, gap_ms: 600000, points});
    assert.deepEqual(model.charging, []);
    assert.deepEqual(model.segments, [[points[0], points[1]], [points[2]]]);
    assert.deepEqual(model.gaps, [[150000, 900000]]);
});
