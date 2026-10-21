const EARTH_RADIUS_METERS = 6371008.8;
const MAX_SNAP_DISTANCE_METERS = 40;
const ROUTE_DEVIATION_METERS = 45;

export const normalizePoint = (value) => {
    if (!Array.isArray(value) || value.length < 2) {
        return null;
    }

    const longitude = Number(value[0]);
    const latitude = Number(value[1]);

    return Number.isFinite(longitude) && Number.isFinite(latitude) ? [longitude, latitude] : null;
};

const isGeographicPoint = (point) => Math.abs(point[0]) <= 180 && Math.abs(point[1]) <= 90;

export const distanceBetweenPoints = (first, second) => {
    if (!first || !second) {
        return Number.POSITIVE_INFINITY;
    }

    if (!isGeographicPoint(first) || !isGeographicPoint(second)) {
        return Math.hypot(second[0] - first[0], second[1] - first[1]);
    }

    const toRadians = Math.PI / 180;
    const latitudeDelta = (second[1] - first[1]) * toRadians;
    const longitudeDelta = (second[0] - first[0]) * toRadians;
    const firstLatitude = first[1] * toRadians;
    const secondLatitude = second[1] * toRadians;
    const haversine = (Math.sin(latitudeDelta / 2) ** 2)
        + Math.cos(firstLatitude) * Math.cos(secondLatitude) * (Math.sin(longitudeDelta / 2) ** 2);

    return 2 * EARTH_RADIUS_METERS * Math.asin(Math.min(1, Math.sqrt(haversine)));
};

export const samePoint = (first, second, toleranceMeters = 0.01) => distanceBetweenPoints(first, second) <= toleranceMeters;

const localProjection = (point, origin) => {
    if (!isGeographicPoint(point) || !isGeographicPoint(origin)) {
        return [point[0] - origin[0], point[1] - origin[1]];
    }

    const radians = Math.PI / 180;
    return [
        (point[0] - origin[0]) * radians * EARTH_RADIUS_METERS * Math.cos(origin[1] * radians),
        (point[1] - origin[1]) * radians * EARTH_RADIUS_METERS,
    ];
};

const pointFromLocalProjection = (point, origin) => {
    if (!isGeographicPoint(origin)) {
        return [origin[0] + point[0], origin[1] + point[1]];
    }

    const radians = Math.PI / 180;
    const longitudeScale = EARTH_RADIUS_METERS * Math.cos(origin[1] * radians);
    return [
        origin[0] + (point[0] / longitudeScale / radians),
        origin[1] + (point[1] / EARTH_RADIUS_METERS / radians),
    ];
};

export const projectPointOntoSegment = (point, start, end) => {
    if (!point || !start || !end) {
        return null;
    }

    const projectedStart = localProjection(start, point);
    const projectedEnd = localProjection(end, point);
    const segmentX = projectedEnd[0] - projectedStart[0];
    const segmentY = projectedEnd[1] - projectedStart[1];
    const squaredLength = (segmentX ** 2) + (segmentY ** 2);
    const ratio = squaredLength === 0
        ? 0
        : Math.max(0, Math.min(1, -((projectedStart[0] * segmentX) + (projectedStart[1] * segmentY)) / squaredLength));
    const projected = pointFromLocalProjection([
        projectedStart[0] + (segmentX * ratio),
        projectedStart[1] + (segmentY * ratio),
    ], point);

    return {
        point: projected,
        distance: distanceBetweenPoints(point, projected),
        t: ratio,
    };
};

export const routeLength = (coordinates) => {
    if (!Array.isArray(coordinates) || coordinates.length < 2) {
        return 0;
    }

    return coordinates.slice(1).reduce((total, point, index) => (
        total + distanceBetweenPoints(coordinates[index], point)
    ), 0);
};

const getEdgeCoordinates = (edge, fromNode, toNode) => {
    const coordinates = Array.isArray(edge.geometry?.coordinates) ? edge.geometry.coordinates : edge.coordinates;
    const normalized = Array.isArray(coordinates) ? coordinates.map(normalizePoint).filter(Boolean) : [];

    if (normalized.length >= 2) {
        const fromPoint = normalizePoint([fromNode.x, fromNode.y]);
        const toPoint = normalizePoint([toNode.x, toNode.y]);
        const forwardError = distanceBetweenPoints(normalized[0], fromPoint) + distanceBetweenPoints(normalized.at(-1), toPoint);
        const reverseError = distanceBetweenPoints(normalized[0], toPoint) + distanceBetweenPoints(normalized.at(-1), fromPoint);

        if (reverseError < forwardError && reverseError <= 0.5) {
            return normalized.reverse();
        }

        return forwardError <= 0.5 ? normalized : [];
    }

    const from = normalizePoint([fromNode.x, fromNode.y]);
    const to = normalizePoint([toNode.x, toNode.y]);

    return from && to ? [from, to] : [];
};

const appendCoordinates = (destination, incoming) => {
    for (const coordinate of incoming) {
        if (destination.length === 0 || !samePoint(destination[destination.length - 1], coordinate)) {
            destination.push([...coordinate]);
        }
    }
};

const edgeCandidates = (graph) => {
    const nodes = new Map((graph?.nodes ?? []).map((node) => [String(node.id), node]));

    return (graph?.edges ?? []).map((edge) => {
        const fromId = String(edge.from_node_id ?? '');
        const toId = String(edge.to_node_id ?? '');
        const fromNode = nodes.get(fromId);
        const toNode = nodes.get(toId);

        return {
            ...edge,
            fromId,
            toId,
            coordinates: fromNode && toNode ? getEdgeCoordinates(edge, fromNode, toNode) : [],
        };
    }).filter((edge) => edge.accessible !== false && edge.fromId && edge.toId && edge.coordinates.length >= 2);
};

export const snapPointToGraph = (point, graph, maximumDistance = MAX_SNAP_DISTANCE_METERS) => {
    const normalized = normalizePoint(point);
    if (!normalized) {
        return null;
    }

    const projections = [];

    for (const edge of edgeCandidates(graph)) {
        for (let index = 0; index < edge.coordinates.length - 1; index += 1) {
            const projection = projectPointOntoSegment(normalized, edge.coordinates[index], edge.coordinates[index + 1]);
            if (projection) {
                projections.push({ ...projection, edge, segmentIndex: index });
            }
        }
    }

    projections.sort((first, second) => first.distance - second.distance);
    const nearest = projections[0];
    if (!nearest || nearest.distance > maximumDistance) {
        return null;
    }

    const matches = projections.filter((candidate) => {
        if (candidate.distance > nearest.distance + 0.2) {
            return false;
        }

        const isReciprocal = candidate.edge.fromId === nearest.edge.toId
            && candidate.edge.toId === nearest.edge.fromId
            && candidate.edge.coordinates.length === nearest.edge.coordinates.length
            && candidate.edge.coordinates.every((coordinate, index) => samePoint(
                coordinate,
                nearest.edge.coordinates[nearest.edge.coordinates.length - index - 1],
            ));

        return candidate.edge.id === nearest.edge.id || isReciprocal;
    });

    return {
        ...nearest,
        matches,
    };
};

const edgeTailFromSnap = (edge, snap) => {
    const coordinates = [snap.point];
    appendCoordinates(coordinates, edge.coordinates.slice(snap.segmentIndex + 1));
    return coordinates;
};

const edgeHeadToSnap = (edge, snap) => {
    const coordinates = [...edge.coordinates.slice(0, snap.segmentIndex + 1)];
    appendCoordinates(coordinates, [snap.point]);
    return coordinates;
};

const shortestPath = (adjacency, startId, endId) => {
    const distances = new Map([[startId, 0]]);
    const previous = new Map();
    const remaining = new Set(adjacency.keys());

    while (remaining.size > 0) {
        let currentId = null;
        let currentDistance = Number.POSITIVE_INFINITY;
        for (const candidateId of remaining) {
            const candidateDistance = distances.get(candidateId) ?? Number.POSITIVE_INFINITY;
            if (candidateDistance < currentDistance) {
                currentId = candidateId;
                currentDistance = candidateDistance;
            }
        }

        if (currentId === null || currentDistance === Number.POSITIVE_INFINITY) {
            break;
        }
        if (currentId === endId) {
            break;
        }

        remaining.delete(currentId);
        for (const arc of adjacency.get(currentId) ?? []) {
            const nextDistance = currentDistance + arc.cost;
            if (nextDistance < (distances.get(arc.toId) ?? Number.POSITIVE_INFINITY)) {
                distances.set(arc.toId, nextDistance);
                previous.set(arc.toId, { fromId: currentId, arc });
            }
        }
    }

    if (!distances.has(endId)) {
        return null;
    }

    const arcs = [];
    let cursor = endId;
    while (cursor !== startId) {
        const step = previous.get(cursor);
        if (!step) {
            return null;
        }
        arcs.unshift(step.arc);
        cursor = step.fromId;
    }

    return { arcs, cost: distances.get(endId) };
};

const buildDirectedAdjacency = (graph) => {
    const adjacency = new Map((graph?.nodes ?? []).map((node) => [String(node.id), []]));
    for (const edge of edgeCandidates(graph)) {
        const geometricCost = routeLength(edge.coordinates);
        const storedCost = Number(edge.distance);
        const arcCost = Number.isFinite(storedCost) && storedCost > 0 ? storedCost : geometricCost;
        if (!Number.isFinite(arcCost) || arcCost <= 0) {
            continue;
        }

        if (!adjacency.has(edge.fromId)) {
            adjacency.set(edge.fromId, []);
        }
        if (!adjacency.has(edge.toId)) {
            adjacency.set(edge.toId, []);
        }
        adjacency.get(edge.fromId).push({
            toId: edge.toId,
            cost: arcCost,
            coordinates: edge.coordinates,
            edgeId: String(edge.id ?? `${edge.fromId}:${edge.toId}`),
        });
    }
    return adjacency;
};

const routeOnSameEdge = (startSnap, destinationSnap) => {
    const first = startSnap.edge;
    const second = destinationSnap.edge;
    if (first.id !== second.id || first.fromId !== second.fromId || first.toId !== second.toId) {
        return null;
    }

    const startMeasure = routeLength(first.coordinates.slice(0, startSnap.segmentIndex + 1))
        + routeLength([first.coordinates[startSnap.segmentIndex], startSnap.point]);
    const destinationMeasure = routeLength(first.coordinates.slice(0, destinationSnap.segmentIndex + 1))
        + routeLength([first.coordinates[destinationSnap.segmentIndex], destinationSnap.point]);
    if (destinationMeasure < startMeasure) {
        return null;
    }

    const path = [startSnap.point];
    appendCoordinates(path, first.coordinates.slice(startSnap.segmentIndex + 1, destinationSnap.segmentIndex + 1));
    appendCoordinates(path, [destinationSnap.point]);
    return path.length >= 2 ? path : null;
};

export const resolveGraphRoute = (graph, startPoint, destinationPoint) => {
    const startSnap = snapPointToGraph(startPoint, graph);
    const destinationSnap = snapPointToGraph(destinationPoint, graph);
    if (!startSnap || !destinationSnap) {
        return { reachable: false, fullPath: [], nodePath: [], edgePath: [], reason: 'snap-too-far' };
    }

    const adjacency = buildDirectedAdjacency(graph);
    const candidateRoutes = [];
    const startMatches = startSnap.matches ?? [startSnap];
    const destinationMatches = destinationSnap.matches ?? [destinationSnap];

    for (const matchedStart of startMatches) {
        const startEdge = matchedStart.edge;
        for (const matchedDestination of destinationMatches) {
            const destinationEdge = matchedDestination.edge;
            const directPath = routeOnSameEdge(matchedStart, matchedDestination);
            if (directPath) {
                candidateRoutes.push({
                    fullPath: directPath,
                    nodePath: [],
                    edgePath: [startEdge.id],
                    distance: routeLength(directPath),
                    startSnap: matchedStart,
                    destinationSnap: matchedDestination,
                });
                continue;
            }

            const startPrefix = edgeTailFromSnap(startEdge, matchedStart);
            const destinationPrefix = edgeHeadToSnap(destinationEdge, matchedDestination);
            const middle = shortestPath(adjacency, startEdge.toId, destinationEdge.fromId);
            if (!middle) {
                continue;
            }

            const coordinates = [matchedStart.point];
            appendCoordinates(coordinates, startPrefix.slice(1));
            for (const arc of middle.arcs) {
                appendCoordinates(coordinates, arc.coordinates);
            }
            appendCoordinates(coordinates, destinationPrefix.slice(0, -1));
            appendCoordinates(coordinates, [matchedDestination.point]);

            if (coordinates.length >= 2) {
                const traversesStartEdge = routeLength(startPrefix) > 0.01;
                const traversesDestinationEdge = routeLength(destinationPrefix) > 0.01;
                const nodePath = [];
                if (samePoint(matchedStart.point, startEdge.coordinates[0])) {
                    nodePath.push(startEdge.fromId);
                }
                if (nodePath.at(-1) !== startEdge.toId) {
                    nodePath.push(startEdge.toId);
                }
                middle.arcs.forEach((arc) => {
                    if (nodePath.at(-1) !== arc.toId) {
                        nodePath.push(arc.toId);
                    }
                });
                if (traversesDestinationEdge
                    && samePoint(matchedDestination.point, destinationEdge.coordinates.at(-1))
                    && nodePath.at(-1) !== destinationEdge.toId) {
                    nodePath.push(destinationEdge.toId);
                }

                candidateRoutes.push({
                    fullPath: coordinates,
                    nodePath,
                    edgePath: [
                        ...(traversesStartEdge ? [startEdge.id] : []),
                        ...middle.arcs.map((arc) => arc.edgeId),
                        ...(traversesDestinationEdge ? [destinationEdge.id] : []),
                    ],
                    distance: routeLength(coordinates),
                    startSnap: matchedStart,
                    destinationSnap: matchedDestination,
                });
            }
        }
    }

    candidateRoutes.sort((first, second) => first.distance - second.distance);
    const best = candidateRoutes[0];
    if (!best) {
        return { reachable: false, fullPath: [], nodePath: [], edgePath: [], reason: 'disconnected' };
    }

    return { ...best, reachable: true };
};

export const coordinatesFromPlaceFeature = (feature) => {
    const geometry = feature?.geometry;
    if (!geometry) {
        return null;
    }
    if (geometry.type === 'Point') {
        return normalizePoint(geometry.coordinates);
    }
    return null;
};

export const splitRouteByUserPosition = (fullPath, userPoint, previousProgress = 0, { maximumAdvanceMeters = Infinity } = {}) => {
    if (!Array.isArray(fullPath) || fullPath.length < 2) {
        return { completedPath: [], remainingPath: fullPath ?? [], progress: 0, deviated: false, point: null };
    }
    const user = normalizePoint(userPoint);
    if (!user) {
        return { completedPath: [], remainingPath: [...fullPath], progress: previousProgress, deviated: false, point: null };
    }

    const cumulative = [0];
    for (let index = 0; index < fullPath.length - 1; index += 1) {
        cumulative.push(cumulative[index] + distanceBetweenPoints(fullPath[index], fullPath[index + 1]));
    }
    const totalLength = cumulative[cumulative.length - 1];
    const previousMeasure = Math.max(0, Math.min(totalLength, previousProgress * totalLength));
    const minimumMeasure = Math.max(0, previousMeasure - 8);
    let best = null;

    for (let index = 0; index < fullPath.length - 1; index += 1) {
        if (cumulative[index + 1] < minimumMeasure) {
            continue;
        }
        const maximumMeasure = Math.min(totalLength, previousMeasure + maximumAdvanceMeters);
        if (cumulative[index] > maximumMeasure) continue;
        const segmentLength = cumulative[index + 1] - cumulative[index];
        const endFraction = segmentLength > 0 ? Math.min(1, (maximumMeasure - cumulative[index]) / segmentLength) : 1;
        const segmentEnd = fullPath[index].map((value, axis) => value + (fullPath[index + 1][axis] - value) * endFraction);
        const projection = projectPointOntoSegment(user, fullPath[index], segmentEnd);
        if (!projection) {
            continue;
        }
        const measure = cumulative[index] + (distanceBetweenPoints(fullPath[index], projection.point));
        const similarDistance = best && Math.abs(projection.distance - best.distance) <= 2;
        if (!best || (similarDistance
            ? Math.abs(measure - previousMeasure) < Math.abs(best.measure - previousMeasure)
            : projection.distance < best.distance)) {
            best = { ...projection, index, measure };
        }
    }

    if (!best) {
        return { completedPath: [], remainingPath: [...fullPath], progress: previousProgress, deviated: false, point: null };
    }
    if (best.distance > ROUTE_DEVIATION_METERS) {
        return { completedPath: [], remainingPath: [...fullPath], progress: previousProgress, deviated: true, point: null, distance: best.distance };
    }

    const progress = totalLength > 0 ? Math.max(previousProgress, Math.min(1, best.measure / totalLength)) : 0;
    const measure = progress * totalLength;
    const index = Math.max(0, cumulative.findIndex((value, position) => position > 0 && value >= measure) - 1);
    const length = cumulative[index + 1] - cumulative[index];
    const fraction = length > 0 ? (measure - cumulative[index]) / length : 0;
    const projectedPoint = fullPath[index].map((value, axis) => value + (fullPath[index + 1][axis] - value) * fraction);
    const completedPath = [...fullPath.slice(0, index + 1), projectedPoint];
    const remainingPath = [projectedPoint, ...fullPath.slice(index + 1)];

    return {
        completedPath: completedPath.filter((point, index, path) => index === 0 || !samePoint(point, path[index - 1])),
        remainingPath: remainingPath.filter((point, index, path) => index === 0 || !samePoint(point, path[index - 1])),
        progress,
        deviated: false,
        point: projectedPoint,
        distance: best.distance,
    };
};
