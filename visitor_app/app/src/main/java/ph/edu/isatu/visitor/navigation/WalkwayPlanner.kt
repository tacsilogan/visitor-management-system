package ph.edu.isatu.visitor.navigation

import java.util.PriorityQueue
import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.atan2
import kotlin.math.ceil
import kotlin.math.cos
import kotlin.math.floor
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min

/** A walking route an administrator recorded in Campus Map → Routes (campus_map.php walking_routes). */
data class Walkway(val id: Long, val officeCode: String, val name: String, val points: List<GeoPoint>)

enum class TurnDirection { SLIGHT_LEFT, LEFT, SHARP_LEFT, SLIGHT_RIGHT, RIGHT, SHARP_RIGHT }

/** A turn on a [WalkingPath], [atMeters] along the path from the visitor. */
data class PathTurn(val atMeters: Double, val direction: TurnDirection, val point: GeoPoint)

/**
 * The way to walk from the visitor to a target. [points] runs from the visitor to the
 * target: a straight [joinMeters] onto the nearest walkway (index 0 to [walkwayStart]),
 * then along recorded walkways (to [walkwayEnd]), then a straight [finishMeters] to the
 * target. [turns] are the turns along the walkway part, in order.
 */
data class WalkingPath(
    val points: List<GeoPoint>,
    val lengthMeters: Double,
    val joinMeters: Double,
    val finishMeters: Double,
    val walkwayStart: Int,
    val walkwayEnd: Int,
    val turns: List<PathTurn>,
    /** Network nodes the path uses; the next plan prefers them, so the route doesn't flicker. */
    internal val nodes: Set<Int> = emptySet(),
) {
    val target: GeoPoint get() = points.last()

    /** The point [meters] along the path from the visitor, clamped to its ends. */
    fun pointAt(meters: Double): GeoPoint {
        if (meters <= 0 || points.size == 1) return points.first()
        var travelled = 0.0
        for (index in 1 until points.size) {
            val length = GeoMath.distanceMeters(points[index - 1], points[index])
            if (length > 0 && travelled + length >= meters) {
                val fraction = (meters - travelled) / length
                val from = points[index - 1]
                val to = points[index]
                return GeoPoint(
                    from.latitude + (to.latitude - from.latitude) * fraction,
                    from.longitude + (to.longitude - from.longitude) * fraction,
                )
            }
            travelled += length
        }
        return points.last()
    }

    /**
     * The same path from its point on the walkway, without the straight leg onto it: for a
     * visitor who is on the walkway as far as GPS can tell (see [PathFollower]), so distances
     * and turns are measured along the blue line itself.
     */
    fun onWalkway(): WalkingPath =
        if (walkwayStart == 0) {
            this
        } else {
            WalkingPath(
                points = points.subList(walkwayStart, points.size).toList(),
                lengthMeters = lengthMeters - joinMeters,
                joinMeters = 0.0,
                finishMeters = finishMeters,
                walkwayStart = 0,
                walkwayEnd = walkwayEnd - walkwayStart,
                turns = turns.map { it.copy(atMeters = it.atMeters - joinMeters) },
                nodes = nodes,
            )
        }
}

/**
 * Joins the recorded walking routes into one walkway network and finds the shortest walk
 * along it, so visitors are guided around buildings instead of in a straight line.
 *
 * Two routes are only connected where they really meet: where they cross, where they run
 * together along the same walkway (side by side within [TOGETHER_METERS], either way, for at
 * least [TOGETHER_RUN_METERS]), where one starts beside the other (routes from the same
 * gate), or where one ends right on the other. Walkways that merely pass close by, for
 * example on both sides of a building, are never joined, so a path never cuts through a
 * wall between them.
 *
 * The visitor steps onto the nearest walkway straight across, and off it at its point
 * nearest the target (along the target office's own routes when it has any); those two
 * short legs are straight lines, and walking off the walkways counts [OFF_WALKWAY_FACTOR]
 * times as far, so the path stays on recorded routes. Positions are handled on a flat
 * local projection, which is exact enough for a campus.
 */
class WalkwayPlanner(walkways: List<Walkway>) {
    private val originLatitude: Double
    private val originLongitude: Double
    private val metersPerLongitude: Double

    private val xs: DoubleArray
    private val ys: DoubleArray
    private val walkwayOf: IntArray
    private val edgesTo: Array<IntArray>
    private val edgesCost: Array<DoubleArray>
    /** Consecutive node pairs along each walkway, for finding the nearest walkway point. */
    private val segmentFrom: IntArray
    private val segmentTo: IntArray
    private val segmentCells = HashMap<Long, MutableList<Int>>()
    /** The office each walkway leads to. */
    private val walkwayOffice: List<String>

    /** True when no walkway was recorded, so there is nothing to guide along. */
    val isEmpty: Boolean

    init {
        val usable = walkways.map { walkway ->
            walkway to walkway.points.filterIndexed { index, point -> index == 0 || point != walkway.points[index - 1] }
        }.filter { it.second.size >= 2 }
        walkwayOffice = usable.map { it.first.officeCode }
        val first = usable.firstOrNull()?.second?.first()
        originLatitude = first?.latitude ?: 0.0
        originLongitude = first?.longitude ?: 0.0
        metersPerLongitude = METERS_PER_DEGREE * cos(originLatitude * PI / 180)

        val nodeX = ArrayList<Double>()
        val nodeY = ArrayList<Double>()
        val nodeWalkway = ArrayList<Int>()
        val walkwayNodes = ArrayList<IntRange>()
        val fromList = ArrayList<Int>()
        val toList = ArrayList<Int>()
        usable.forEachIndexed { walkwayIndex, (_, points) ->
            val start = nodeX.size
            var previousX = 0.0
            var previousY = 0.0
            points.forEachIndexed { index, point ->
                val x = toX(point.longitude)
                val y = toY(point.latitude)
                if (index > 0) {
                    val length = hypot(x - previousX, y - previousY)
                    if (length < MIN_STEP_METERS) return@forEachIndexed
                    // Points every few meters, so routes that meet can be joined where they meet.
                    val pieces = ceil(length / NODE_SPACING_METERS).toInt()
                    for (piece in 1 until pieces) {
                        val fraction = piece.toDouble() / pieces
                        nodeX += previousX + (x - previousX) * fraction
                        nodeY += previousY + (y - previousY) * fraction
                        nodeWalkway += walkwayIndex
                    }
                }
                nodeX += x
                nodeY += y
                nodeWalkway += walkwayIndex
                previousX = x
                previousY = y
            }
            val end = nodeX.size - 1
            walkwayNodes += start..end
            for (node in start until end) {
                fromList += node
                toList += node + 1
            }
        }
        for (segment in fromList.indices) {
            val a = fromList[segment]
            val b = toList[segment]
            forEachCell(min(nodeX[a], nodeX[b]), min(nodeY[a], nodeY[b]), max(nodeX[a], nodeX[b]), max(nodeY[a], nodeY[b])) { cell ->
                segmentCells.getOrPut(cell) { ArrayList() } += segment
            }
        }

        // Where two routes cross, a junction exactly at the crossing joins the four ends of
        // the two stretches, so a path turns there instead of hopping between nearby points.
        val walkwayNodeCount = nodeX.size
        val junctionEdges = ArrayList<Triple<Int, Int, Double>>()
        for (segment in fromList.indices) {
            val a = fromList[segment]
            val b = toList[segment]
            val candidates = HashSet<Int>()
            forEachCell(min(nodeX[a], nodeX[b]), min(nodeY[a], nodeY[b]), max(nodeX[a], nodeX[b]), max(nodeY[a], nodeY[b])) { cell ->
                segmentCells[cell]?.let(candidates::addAll)
            }
            for (other in candidates) {
                if (other <= segment) continue
                val c = fromList[other]
                val d = toList[other]
                if (nodeWalkway[c] == nodeWalkway[a]) continue
                val (x, y) = crossingPoint(nodeX[a], nodeY[a], nodeX[b], nodeY[b], nodeX[c], nodeY[c], nodeX[d], nodeY[d]) ?: continue
                val junction = nodeX.size
                nodeX += x
                nodeY += y
                nodeWalkway += nodeWalkway[a]
                for (end in intArrayOf(a, b, c, d)) junctionEdges += Triple(junction, end, hypot(x - nodeX[end], y - nodeY[end]))
            }
        }

        xs = nodeX.toDoubleArray()
        ys = nodeY.toDoubleArray()
        walkwayOf = nodeWalkway.toIntArray()
        segmentFrom = fromList.toIntArray()
        segmentTo = toList.toIntArray()
        isEmpty = segmentFrom.isEmpty()

        val neighbors = Array(xs.size) { HashMap<Int, Double>() }
        fun link(a: Int, b: Int, cost: Double) {
            if (a == b) return
            if (cost < (neighbors[a][b] ?: Double.MAX_VALUE)) {
                neighbors[a][b] = cost
                neighbors[b][a] = cost
            }
        }
        for (segment in segmentFrom.indices) link(segmentFrom[segment], segmentTo[segment], distance(segmentFrom[segment], segmentTo[segment]))
        for ((junction, end, length) in junctionEdges) link(junction, end, length)
        // Junctions are left out here: they belong to two walkways at once.
        val nodeCells = HashMap<Long, MutableList<Int>>()
        for (node in 0 until walkwayNodeCount) nodeCells.getOrPut(cellKey(xs[node], ys[node])) { ArrayList() } += node
        fun nearestOnWalkway(node: Int, walkway: Int, radius: Double): Int? {
            var best: Int? = null
            var bestDistance = radius
            forEachCell(xs[node] - radius, ys[node] - radius, xs[node] + radius, ys[node] + radius) { cell ->
                nodeCells[cell]?.forEach { other ->
                    if (walkwayOf[other] == walkway) {
                        val d = distance(node, other)
                        if (d <= bestDistance) {
                            bestDistance = d
                            best = other
                        }
                    }
                }
            }
            return best
        }

        // Only walkways whose areas overlap (plus a margin) can meet.
        val boxes = walkwayNodes.map { nodes ->
            doubleArrayOf(nodes.minOf { xs[it] }, nodes.minOf { ys[it] }, nodes.maxOf { xs[it] }, nodes.maxOf { ys[it] })
        }
        fun mayMeet(a: Int, b: Int): Boolean {
            val margin = START_LINK_METERS
            return a != b && boxes[a][0] - margin <= boxes[b][2] && boxes[b][0] - margin <= boxes[a][2] &&
                boxes[a][1] - margin <= boxes[b][3] && boxes[b][1] - margin <= boxes[a][3]
        }

        // A route starting beside another route (several routes from one gate, or a route
        // branching off another), or ending on one. Ends must practically touch: two offices'
        // doors a few meters apart may be on opposite sides of a wall.
        walkwayNodes.forEachIndexed { walkway, nodes ->
            for ((end, radius) in listOf(nodes.first to START_LINK_METERS, nodes.last to END_LINK_METERS)) {
                for (other in walkwayNodes.indices) {
                    if (!mayMeet(walkway, other)) continue
                    nearestOnWalkway(end, other, radius)?.let { link(end, it, distance(end, it) + TRANSFER_PENALTY_METERS) }
                }
            }
        }

        // Routes running together along the same walkway (recorded twice, a few meters apart).
        walkwayNodes.forEachIndexed { walkway, nodes ->
            for (other in walkwayNodes.indices) {
                if (!mayMeet(walkway, other)) continue
                var run = ArrayList<Pair<Int, Int>>()
                fun closeRun() {
                    if (run.size >= 2 && distance(run.first().first, run.last().first) >= TOGETHER_RUN_METERS) {
                        run.forEach { (node, match) -> link(node, match, distance(node, match) + TRANSFER_PENALTY_METERS) }
                    }
                    run = ArrayList()
                }
                for (node in nodes) {
                    val match = nearestOnWalkway(node, other, TOGETHER_METERS)
                    if (match != null && parallel(node, nodes, match, walkwayNodes[other])) run += node to match else closeRun()
                }
                closeRun()
            }
        }

        edgesTo = Array(xs.size) { node -> neighbors[node].keys.toIntArray() }
        edgesCost = Array(xs.size) { node -> DoubleArray(edgesTo[node].size) { index -> neighbors[node].getValue(edgesTo[node][index]) } }
    }

    /**
     * The shortest walk from [from] to [to] along the walkways, or null when no walkway passes
     * within [JOIN_MAX_METERS] of the visitor or [FINISH_MAX_METERS] of the target (the app
     * then points straight at the target). Passing the [previous] path keeps the route steady
     * when two ways are about as long; [targetOffice] (an office code) makes the path arrive
     * along that office's own routes when it has any.
     */
    fun plan(from: GeoPoint, to: GeoPoint, previous: WalkingPath? = null, targetOffice: String? = null): WalkingPath? {
        if (isEmpty) return null
        val px = toX(from.longitude)
        val py = toY(from.latitude)
        val tx = toX(to.longitude)
        val ty = toY(to.latitude)
        val entries = nearestWalkways(nearbySegments(px, py, JOIN_MAX_METERS))
        // An office is reached along its own routes when it has any near its pin: a route
        // to another office may pass close by on the other side of a wall.
        val exitCandidates = nearbySegments(tx, ty, FINISH_MAX_METERS)
        val ownRoutes = if (targetOffice.isNullOrBlank()) emptyList() else exitCandidates.filter { walkwayOffice[walkwayOf[it.from]] == targetOffice }
        val exits = nearestWalkways(ownRoutes.ifEmpty { exitCandidates })
        if (entries.isEmpty() || exits.isEmpty()) return null

        // Every node starts at the cost of walking onto the walkway and along it to that node.
        val best = DoubleArray(xs.size) { Double.MAX_VALUE }
        val cameFrom = IntArray(xs.size) { NONE }
        val entryOf = arrayOfNulls<Projection>(xs.size)
        val queue = PriorityQueue<Pair<Double, Int>>(compareBy { it.first })
        for (entry in entries) {
            val joinCost = entry.distance * OFF_WALKWAY_FACTOR
            for ((node, partial) in listOf(entry.from to entry.fromFrom, entry.to to entry.fromTo)) {
                val cost = joinCost + partial
                if (cost < best[node]) {
                    best[node] = cost
                    entryOf[node] = entry
                    queue += cost to node
                }
            }
        }
        val preferred = previous?.takeIf { it.target == to }?.nodes.orEmpty()
        while (queue.isNotEmpty()) {
            val (cost, node) = queue.poll()!!
            if (cost > best[node]) continue
            val targets = edgesTo[node]
            val costs = edgesCost[node]
            for (index in targets.indices) {
                val next = targets[index]
                val step = if (node in preferred && next in preferred) costs[index] * STICKY_FACTOR else costs[index]
                val total = cost + step
                if (total < best[next]) {
                    best[next] = total
                    cameFrom[next] = node
                    entryOf[next] = null
                    queue += total to next
                }
            }
        }

        // Leave the walkway where it passes nearest the target.
        var bestTotal = Double.MAX_VALUE
        var exitNode = NONE
        var exit: Projection? = null
        var direct: Projection? = null
        for (candidate in exits) {
            val finishCost = candidate.distance * OFF_WALKWAY_FACTOR
            for ((node, partial) in listOf(candidate.from to candidate.fromFrom, candidate.to to candidate.fromTo)) {
                if (best[node] == Double.MAX_VALUE) continue
                val total = best[node] + partial + finishCost
                if (total < bestTotal) {
                    bestTotal = total
                    exitNode = node
                    exit = candidate
                    direct = null
                }
            }
            // The visitor and the target beside the same stretch of walkway.
            entries.firstOrNull { it.segment == candidate.segment }?.let { entry ->
                val total = entry.distance * OFF_WALKWAY_FACTOR + abs(entry.fromFrom - candidate.fromFrom) + finishCost
                if (total < bestTotal) {
                    bestTotal = total
                    exitNode = NONE
                    exit = candidate
                    direct = entry
                }
            }
        }
        val leave = exit ?: return null

        val xy = ArrayList<Pair<Double, Double>>()
        val used = HashSet<Int>()
        xy += px to py
        val sameStretch = direct
        if (sameStretch != null) {
            xy += sameStretch.x to sameStretch.y
        } else {
            val chain = ArrayList<Int>()
            var node = exitNode
            while (node != NONE) {
                chain += node
                if (entryOf[node] != null) break
                node = cameFrom[node]
            }
            chain.reverse()
            val join = entryOf[chain.first()] ?: return null
            xy += join.x to join.y
            for (step in chain) {
                xy += xs[step] to ys[step]
                used += step
            }
        }
        val walkwayStartIndex = 1
        xy += leave.x to leave.y
        val walkwayEndIndex = xy.size - 1
        xy += tx to ty

        // Drop repeated points (an entry exactly on a node), keeping the indices in step.
        val points = ArrayList<Pair<Double, Double>>()
        var start = walkwayStartIndex
        var end = walkwayEndIndex
        xy.forEachIndexed { index, point ->
            val last = points.lastOrNull()
            if (last != null && hypot(point.first - last.first, point.second - last.second) < DUPLICATE_METERS) {
                if (index <= walkwayStartIndex) start -= 1
                if (index <= walkwayEndIndex) end -= 1
            } else {
                points += point
            }
        }
        start = start.coerceIn(0, points.size - 1)
        end = end.coerceIn(start, points.size - 1)

        val cumulative = DoubleArray(points.size)
        for (index in 1 until points.size) {
            cumulative[index] = cumulative[index - 1] + hypot(points[index].first - points[index - 1].first, points[index].second - points[index - 1].second)
        }
        // The ends are the visitor's and the target's own positions, exactly.
        val geoPoints = points.map { (x, y) -> GeoPoint(toLatitude(y), toLongitude(x)) }.toMutableList()
        geoPoints[0] = from
        geoPoints[geoPoints.lastIndex] = to
        return WalkingPath(
            points = geoPoints,
            lengthMeters = cumulative.last(),
            joinMeters = cumulative[start],
            finishMeters = cumulative.last() - cumulative[end],
            walkwayStart = start,
            walkwayEnd = end,
            turns = findTurns(points, cumulative, start, end),
            nodes = used,
        )
    }

    /** The turns along the walkway part of a path: where its direction changes by 30° or more. */
    private fun findTurns(points: List<Pair<Double, Double>>, cumulative: DoubleArray, start: Int, end: Int): List<PathTurn> {
        val total = cumulative.last()
        val from = cumulative[start]
        fun at(meters: Double): Pair<Double, Double> {
            val clamped = meters.coerceIn(0.0, total)
            for (index in 1 until points.size) {
                if (cumulative[index] >= clamped) {
                    val length = cumulative[index] - cumulative[index - 1]
                    val fraction = if (length > 0) (clamped - cumulative[index - 1]) / length else 0.0
                    val a = points[index - 1]
                    val b = points[index]
                    return (a.first + (b.first - a.first) * fraction) to (a.second + (b.second - a.second) * fraction)
                }
            }
            return points.last()
        }
        // A bend is judged over [TURN_WINDOW_METERS] on each side, so GPS wiggle along a
        // recorded route does not count as a turn.
        val candidates = ArrayList<Triple<Int, Double, Double>>()
        for (index in (start + 1) until min(points.size - 1, end + 1)) {
            val here = cumulative[index]
            val before = at(max(from, here - TURN_WINDOW_METERS))
            val after = at(min(total, here + TURN_WINDOW_METERS))
            val point = points[index]
            if (hypot(point.first - before.first, point.second - before.second) < TURN_MIN_LEG_METERS ||
                hypot(after.first - point.first, after.second - point.second) < TURN_MIN_LEG_METERS
            ) {
                continue
            }
            val incoming = bearing(before, point)
            val outgoing = bearing(point, after)
            val angle = GeoMath.signedDifference(outgoing, incoming)
            if (abs(angle) >= TURN_MIN_DEGREES) candidates += Triple(index, here, angle)
        }
        // One bend shows up at several points in a row; keep its sharpest point.
        val turns = ArrayList<PathTurn>()
        var group = ArrayList<Triple<Int, Double, Double>>()
        fun closeGroup() {
            val sharpest = group.maxByOrNull { abs(it.third) } ?: return
            val (index, meters, angle) = sharpest
            val point = points[index]
            turns += PathTurn(meters, direction(angle), GeoPoint(toLatitude(point.second), toLongitude(point.first)))
            group = ArrayList()
        }
        for (candidate in candidates) {
            val last = group.lastOrNull()
            if (last != null && (candidate.second - last.second > TURN_MERGE_METERS || (candidate.third > 0) != (last.third > 0))) closeGroup()
            group += candidate
        }
        closeGroup()
        return turns
    }

    private fun direction(angle: Double): TurnDirection {
        val size = abs(angle)
        val right = angle > 0
        return when {
            size < SLIGHT_TURN_MAX_DEGREES -> if (right) TurnDirection.SLIGHT_RIGHT else TurnDirection.SLIGHT_LEFT
            size < SHARP_TURN_MIN_DEGREES -> if (right) TurnDirection.RIGHT else TurnDirection.LEFT
            else -> if (right) TurnDirection.SHARP_RIGHT else TurnDirection.SHARP_LEFT
        }
    }

    /**
     * Where to step onto (or off) the walkways: the nearest point of each walkway, straight
     * across rather than diagonally, and only walkways about as near as the nearest one. A
     * walkway a little farther may be on the other side of a wall.
     */
    private fun nearestWalkways(found: List<Projection>): List<Projection> {
        if (found.isEmpty()) return found
        val nearestOf = HashMap<Int, Double>()
        for (projection in found) {
            val walkway = walkwayOf[projection.from]
            if (projection.distance < (nearestOf[walkway] ?: Double.MAX_VALUE)) nearestOf[walkway] = projection.distance
        }
        val nearest = nearestOf.values.min()
        return found.filter { projection ->
            val own = nearestOf.getValue(walkwayOf[projection.from])
            projection.distance <= own + STRAIGHT_ACROSS_SLACK_METERS && own <= nearest + NEARER_WALKWAY_SLACK_METERS
        }
    }

    /** The nearest point of each walkway stretch within [radius] meters of (x, y). */
    private fun nearbySegments(x: Double, y: Double, radius: Double): List<Projection> {
        val found = HashSet<Int>()
        forEachCell(x - radius, y - radius, x + radius, y + radius) { cell -> segmentCells[cell]?.let(found::addAll) }
        return found.mapNotNull { segment ->
            val a = segmentFrom[segment]
            val b = segmentTo[segment]
            val dx = xs[b] - xs[a]
            val dy = ys[b] - ys[a]
            val lengthSquared = dx * dx + dy * dy
            val t = if (lengthSquared > 0) (((x - xs[a]) * dx + (y - ys[a]) * dy) / lengthSquared).coerceIn(0.0, 1.0) else 0.0
            val qx = xs[a] + t * dx
            val qy = ys[a] + t * dy
            val d = hypot(x - qx, y - qy)
            if (d > radius) {
                null
            } else {
                val length = hypot(dx, dy)
                Projection(segment, a, b, qx, qy, d, t * length, (1 - t) * length)
            }
        }
    }

    /** Whether two walkways run the same way (either direction) at these two nodes. */
    private fun parallel(node: Int, nodes: IntRange, other: Int, otherNodes: IntRange): Boolean {
        val (ax, ay) = direction(node, nodes)
        val (bx, by) = direction(other, otherNodes)
        val lengths = hypot(ax, ay) * hypot(bx, by)
        return lengths > 0 && abs(ax * bx + ay * by) / lengths >= PARALLEL_COSINE
    }

    private fun direction(node: Int, nodes: IntRange): Pair<Double, Double> {
        val before = max(nodes.first, node - 1)
        val after = min(nodes.last, node + 1)
        return (xs[after] - xs[before]) to (ys[after] - ys[before])
    }

    /**
     * Where stretch a–b crosses or touches stretch c–d, or null when they don't meet or lie
     * along each other (that is "running together", handled separately).
     */
    private fun crossingPoint(
        ax: Double,
        ay: Double,
        bx: Double,
        by: Double,
        cx: Double,
        cy: Double,
        dx: Double,
        dy: Double,
    ): Pair<Double, Double>? {
        val rx = bx - ax
        val ry = by - ay
        val sx = dx - cx
        val sy = dy - cy
        val denominator = rx * sy - ry * sx
        if (abs(denominator) < PARALLEL_EPSILON) return null
        val t = ((cx - ax) * sy - (cy - ay) * sx) / denominator
        val u = ((cx - ax) * ry - (cy - ay) * rx) / denominator
        if (t < -PARALLEL_EPSILON || t > 1 + PARALLEL_EPSILON || u < -PARALLEL_EPSILON || u > 1 + PARALLEL_EPSILON) return null
        return (ax + t * rx) to (ay + t * ry)
    }

    private fun bearing(from: Pair<Double, Double>, to: Pair<Double, Double>): Double =
        GeoMath.normalizeDegrees(atan2(to.first - from.first, to.second - from.second) * 180 / PI)

    private fun distance(a: Int, b: Int): Double = hypot(xs[a] - xs[b], ys[a] - ys[b])

    private fun toX(longitude: Double) = (longitude - originLongitude) * metersPerLongitude
    private fun toY(latitude: Double) = (latitude - originLatitude) * METERS_PER_DEGREE
    private fun toLongitude(x: Double) = originLongitude + x / metersPerLongitude
    private fun toLatitude(y: Double) = originLatitude + y / METERS_PER_DEGREE

    private fun cellKey(x: Double, y: Double): Long = key(floor(x / CELL_METERS).toLong(), floor(y / CELL_METERS).toLong())

    private fun key(column: Long, row: Long): Long = (column shl 32) xor (row and 0xffffffffL)

    private inline fun forEachCell(minX: Double, minY: Double, maxX: Double, maxY: Double, action: (Long) -> Unit) {
        val firstColumn = floor(minX / CELL_METERS).toLong()
        val lastColumn = floor(maxX / CELL_METERS).toLong()
        val firstRow = floor(minY / CELL_METERS).toLong()
        val lastRow = floor(maxY / CELL_METERS).toLong()
        for (column in firstColumn..lastColumn) {
            for (row in firstRow..lastRow) action(key(column, row))
        }
    }

    /** The nearest point (x, y) of one walkway stretch, [distance] meters away. */
    private class Projection(
        val segment: Int,
        val from: Int,
        val to: Int,
        val x: Double,
        val y: Double,
        val distance: Double,
        val fromFrom: Double,
        val fromTo: Double,
    )

    companion object {
        /** A visitor farther than this from every walkway gets the straight pointer instead. */
        const val JOIN_MAX_METERS = 40.0
        /** A target farther than this from every walkway gets the straight pointer instead. */
        const val FINISH_MAX_METERS = 30.0
        /** Walking off the recorded walkways counts this many times as far. */
        const val OFF_WALKWAY_FACTOR = 2.0
        /** Only walkways within this much of the nearest one are stepped onto or off. */
        const val NEARER_WALKWAY_SLACK_METERS = 5.0
        /** Onto a walkway at its nearest point: at most this much farther. */
        const val STRAIGHT_ACROSS_SLACK_METERS = 0.5
        /** Two routes recorded along the same walkway lie within this distance of each other. */
        const val TOGETHER_METERS = 6.0
        /** ...for at least this far, before they are treated as one walkway. */
        const val TOGETHER_RUN_METERS = 10.0
        /** A route starting this close to another route joins it (routes from one gate). */
        const val START_LINK_METERS = 10.0
        /** A route ending this close to another route joins it. */
        const val END_LINK_METERS = 4.0
        const val TRANSFER_PENALTY_METERS = 3.0
        /** Ways the visitor is already following count as slightly shorter, to stop flicker. */
        const val STICKY_FACTOR = 0.85
        const val NODE_SPACING_METERS = 5.0
        const val TURN_MIN_DEGREES = 30.0
        const val SLIGHT_TURN_MAX_DEGREES = 60.0
        const val SHARP_TURN_MIN_DEGREES = 140.0
        const val TURN_WINDOW_METERS = 8.0
        const val TURN_MIN_LEG_METERS = 2.5
        const val TURN_MERGE_METERS = 8.0
        private const val PARALLEL_COSINE = 0.85
        private const val PARALLEL_EPSILON = 1e-9
        private const val MIN_STEP_METERS = 0.3
        private const val DUPLICATE_METERS = 0.3
        private const val CELL_METERS = 20.0
        private const val METERS_PER_DEGREE = 111_194.93
        private const val NONE = -1
    }
}
