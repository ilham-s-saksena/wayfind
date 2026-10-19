import assert from 'node:assert/strict';
import test from 'node:test';
import {
    coordinatesFromPlaceFeature,
    resolveGraphRoute,
    splitRouteByUserPosition,
} from './navigationRouting.js';

const node = (id, [x, y]) => ({ id, x, y });
const edge = (id, from, to, coordinates) => ({
    id,
    from_node_id: from,
    to_node_id: to,
    distance: coordinates.slice(1).reduce((sum, point, index) => (
        sum + Math.hypot(point[0] - coordinates[index][0], point[1] - coordinates[index][1])
    ), 0),
    accessible: true,
    geometry: { type: 'LineString', coordinates },
});
const bidirectional = (id, from, to, coordinates) => [
    edge(`${id}:forward`, from, to, coordinates),
    edge(`${id}:reverse`, to, from, [...coordinates].reverse()),
];
const graph = (points, edges) => ({
    nodes: Object.entries(points).map(([id, coordinate]) => node(id, coordinate)),
    edges: edges.flat(),
});

const threeWayGraph = () => graph({
    A: [100, 100],
    J: [110, 100],
    B: [120, 100],
    C: [110, 110],
}, [
    bidirectional('AJ', 'A', 'J', [[100, 100], [105, 100], [110, 100]]),
    bidirectional('JB', 'J', 'B', [[110, 100], [120, 100]]),
    bidirectional('JC', 'J', 'C', [[110, 100], [110, 110]]),
]);

test('preserves a long route geometry with all bends', () => {
    const data = graph({ A: [1000, 400], B: [1040, 430] }, [
        bidirectional('AB', 'A', 'B', [[1000, 400], [1010, 400], [1010, 415], [1025, 415], [1040, 430]]),
    ]);
    const result = resolveGraphRoute(data, [1000, 400], [1040, 430]);

    assert.equal(result.reachable, true);
    assert.deepEqual(result.fullPath, [[1000, 400], [1010, 400], [1010, 415], [1025, 415], [1040, 430]]);
    assert.equal(result.edgePath.length, 1);
});

test('takes the connected branch at a three-way junction', () => {
    const result = resolveGraphRoute(threeWayGraph(), [100, 100], [110, 110]);

    assert.equal(result.reachable, true);
    assert.deepEqual(result.nodePath, ['A', 'J', 'C']);
    assert.deepEqual(result.fullPath, [[100, 100], [105, 100], [110, 100], [110, 110]]);
    assert.deepEqual(result.edgePath, ['AJ:forward', 'JC:forward']);
    assert.equal(result.fullPath.some(([x, y]) => x === 120 && y === 100), false);
});

test('routes through a four-way junction in both directions', () => {
    const data = graph({ A: [100, 100], J: [110, 100], B: [120, 100], C: [110, 110], D: [110, 120] }, [
        bidirectional('AJ', 'A', 'J', [[100, 100], [110, 100]]),
        bidirectional('JB', 'J', 'B', [[110, 100], [120, 100]]),
        bidirectional('JC', 'J', 'C', [[110, 100], [110, 110]]),
        bidirectional('CD', 'C', 'D', [[110, 110], [110, 120]]),
    ]);

    assert.deepEqual(resolveGraphRoute(data, [100, 100], [110, 120]).nodePath, ['A', 'J', 'C', 'D']);
    assert.deepEqual(resolveGraphRoute(data, [110, 120], [100, 100]).nodePath, ['D', 'C', 'J', 'A']);
});

test('keeps crossing and nearby unconnected paths disconnected', () => {
    const crossing = graph({ A: [100, 100], B: [120, 120], C: [100, 120], D: [120, 100] }, [
        bidirectional('AB', 'A', 'B', [[100, 100], [120, 120]]),
        bidirectional('CD', 'C', 'D', [[100, 120], [120, 100]]),
    ]);
    const nearby = graph({ A: [100, 100], B: [110, 100], C: [110.1, 100], D: [120, 100] }, [
        bidirectional('AB', 'A', 'B', [[100, 100], [110, 100]]),
        bidirectional('CD', 'C', 'D', [[110.1, 100], [120, 100]]),
    ]);

    assert.equal(resolveGraphRoute(crossing, [100, 100], [100, 120]).reason, 'disconnected');
    assert.equal(resolveGraphRoute(nearby, [100, 100], [120, 100]).reason, 'disconnected');
});

test('rejects edge geometry whose endpoints do not match its graph nodes', () => {
    const data = graph({ A: [100, 100], B: [120, 100] }, [
        bidirectional('AB', 'A', 'B', [[103, 100], [120, 100]]),
    ]);

    assert.equal(resolveGraphRoute(data, [100, 100], [120, 100]).reachable, false);
});

test('splits completed and remaining geometry at a projected mid-edge position', () => {
    const result = splitRouteByUserPosition([[100, 100], [110, 100], [110, 110]], [105, 101]);

    assert.equal(result.deviated, false);
    assert.deepEqual(result.completedPath, [[100, 100], [105, 100]]);
    assert.deepEqual(result.remainingPath, [[105, 100], [110, 100], [110, 110]]);
    assert.ok(result.progress > 0 && result.progress < 1);
});

test('does not invent an access point from a place polygon centroid', () => {
    assert.equal(coordinatesFromPlaceFeature({
        geometry: { type: 'Polygon', coordinates: [[[100, 100], [120, 100], [120, 120], [100, 100]]] },
    }), null);
});
