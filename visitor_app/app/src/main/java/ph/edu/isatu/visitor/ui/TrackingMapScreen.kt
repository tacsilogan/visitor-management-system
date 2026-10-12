package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsTopHeight
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.DirectionsWalk
import androidx.compose.material.icons.automirrored.rounded.ExitToApp
import androidx.compose.material.icons.automirrored.rounded.VolumeOff
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.Explore
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.GpsNotFixed
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.LocationOff
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Navigation
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material.icons.rounded.ZoomOutMap
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.BottomSheetDefaults
import androidx.compose.material3.BottomSheetScaffold
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.SheetValue
import androidx.compose.material3.SmallFloatingActionButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberBottomSheetScaffoldState
import androidx.compose.material3.rememberStandardBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.compose.ui.window.DialogWindowProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import java.util.Locale
import kotlin.math.max
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.CampusGateDto
import ph.edu.isatu.visitor.data.CampusMapData
import ph.edu.isatu.visitor.data.CampusStopDto
import ph.edu.isatu.visitor.data.TrackingData
import ph.edu.isatu.visitor.data.WalkingRouteDto
import ph.edu.isatu.visitor.navigation.CampusArea
import ph.edu.isatu.visitor.navigation.DistanceUnit
import ph.edu.isatu.visitor.navigation.GeoMath
import ph.edu.isatu.visitor.navigation.GeoPoint
import ph.edu.isatu.visitor.navigation.GuidanceEngine
import ph.edu.isatu.visitor.navigation.GuidancePhase
import ph.edu.isatu.visitor.navigation.GuidanceSnapshot
import ph.edu.isatu.visitor.navigation.GuidanceTarget
import ph.edu.isatu.visitor.navigation.GuidanceUpdate
import ph.edu.isatu.visitor.navigation.ArrivalMethod
import ph.edu.isatu.visitor.navigation.ArrivalRule
import ph.edu.isatu.visitor.navigation.displayAccuracy
import ph.edu.isatu.visitor.navigation.HeadingSensor
import ph.edu.isatu.visitor.navigation.LiveLocation
import ph.edu.isatu.visitor.navigation.LocationFix
import ph.edu.isatu.visitor.navigation.NavigationPreferences
import ph.edu.isatu.visitor.navigation.PathFollower
import ph.edu.isatu.visitor.navigation.PositionQuality
import ph.edu.isatu.visitor.navigation.RouteGuidance
import ph.edu.isatu.visitor.navigation.SteadyPosition
import ph.edu.isatu.visitor.navigation.TargetKind
import ph.edu.isatu.visitor.navigation.VoiceGuide
import ph.edu.isatu.visitor.navigation.WalkingPath
import ph.edu.isatu.visitor.navigation.Walkway
import ph.edu.isatu.visitor.navigation.WalkwayPlanner
import ph.edu.isatu.visitor.navigation.displayDistance
import ph.edu.isatu.visitor.navigation.routeInstruction
import ph.edu.isatu.visitor.navigation.sideWords
import ph.edu.isatu.visitor.navigation.walkingMinutes
import ph.edu.isatu.visitor.navigation.walkingMinutesAlongPath

/** What the campus visit screen needs to know about the visit itself. */
data class CampusVisit(
    /** The appointment that owns the visit's tracking session and campus map. */
    val trackingAppointmentId: Long,
    val visitorName: String,
    /** Registration or visit code shown with the pass. */
    val reference: String?,
    /** The QR pass Security scans again at the gate to check out; null hides "Show pass". */
    val passPayload: String?,
    val checkedInAt: String?,
    /** Every stop of a visit to several offices; empty for a single appointment. */
    val stops: List<AppointmentDto> = emptyList(),
    val currentStopId: Long? = null,
    /** The appointment itself, for a single-office visit. */
    val appointment: AppointmentDto? = null,
)

enum class SharingState { PERMISSION_NEEDED, STARTING, SHARING, OFF }

/**
 * The visitor's screen after Security checks them in: a live campus map with walking
 * directions and voice prompts to their office (VISITOR_NAVIGATION.md), their pass for the
 * check-out scan, and control over location sharing.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TrackingMapScreen(
    visit: CampusVisit,
    campusMap: CampusMapData?,
    sharing: SharingState,
    notice: String?,
    onDismissNotice: () -> Unit,
    onBack: () -> Unit,
    onRequestLocationPermission: () -> Unit,
    onRefreshCampusMap: () -> Unit,
    onStopSharing: () -> Unit,
    onShareAgain: () -> Unit,
    /** Reports a confirmed arrival at office stop (appointment id), by "gps" or "visitor". */
    onArrival: (stopAppointmentId: Long, method: String, distanceMeters: Double?, accuracyMeters: Double?) -> Unit,
) {
    val context = LocalContext.current
    val density = LocalDensity.current
    val haptics = LocalHapticFeedback.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val scope = rememberCoroutineScope()

    val preferences = remember { NavigationPreferences(context) }
    var voiceOn by remember { mutableStateOf(preferences.voiceEnabled) }
    var unit by remember { mutableStateOf(preferences.unit) }
    var keepScreenOn by remember { mutableStateOf(preferences.keepScreenOn) }

    val liveLocation = remember { LiveLocation(context) }
    val headingSensor = remember { HeadingSensor(context) }
    val voice = remember { VoiceGuide(context) }
    val engine = remember(visit.trackingAppointmentId) { GuidanceEngine() }
    DisposableEffect(voice) { onDispose { voice.shutdown() } }

    fun permissionGranted() =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
    var hasPermission by remember { mutableStateOf(permissionGranted()) }
    var locationServicesOn by remember { mutableStateOf(true) }
    var volumeMuted by remember { mutableStateOf(false) }
    LaunchedEffect(lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (true) {
                hasPermission = permissionGranted()
                locationServicesOn = liveLocation.servicesEnabled()
                volumeMuted = voice.volumeMuted()
                delay(3_000)
            }
        }
    }

    // Second-by-second position and the compass run only while the screen is visible.
    DisposableEffect(lifecycleOwner, hasPermission) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> {
                    if (hasPermission) liveLocation.start()
                    headingSensor.start()
                }
                Lifecycle.Event.ON_STOP -> {
                    liveLocation.stop()
                    headingSensor.stop()
                    voice.silence()
                }
                else -> Unit
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
            liveLocation.stop()
            headingSensor.stop()
        }
    }

    val view = LocalView.current
    DisposableEffect(keepScreenOn) {
        view.keepScreenOn = keepScreenOn
        onDispose { view.keepScreenOn = false }
    }

    val location by liveLocation.location.collectAsStateWithLifecycle()
    // The same reading with the steady position made of the readings so far: the arrow and
    // the directions follow the steady one, so they do not jump with GPS.
    val live by liveLocation.position.collectAsStateWithLifecycle()
    val steady = live?.steady
    val compass by headingSensor.heading.collectAsStateWithLifecycle()
    val needsCalibration by headingSensor.needsCalibration.collectAsStateWithLifecycle()
    val voiceStatus by voice.status.collectAsStateWithLifecycle()

    LaunchedEffect(location != null) {
        location?.let { headingSensor.updateDeclination(GeoPoint(it.latitude, it.longitude)) }
    }

    // The server clock, so countdowns are right even when the phone's clock is not.
    var clockOffsetMillis by remember { mutableLongStateOf(0L) }
    LaunchedEffect(campusMap?.serverTime) {
        serverTimeMillis(campusMap?.serverTime)?.let { clockOffsetMillis = it - System.currentTimeMillis() }
    }

    val boundary = remember(campusMap?.campusBoundary) {
        campusMap?.campusBoundary.orEmpty().map { GeoPoint(it.latitude, it.longitude) }
    }
    val campusArea = remember(campusMap, boundary) {
        campusMap?.takeIf { it.campusConfigured && boundary.size >= 3 }?.let {
            CampusArea(
                boundary = boundary,
                bufferMeters = it.exitPolicy.boundaryBufferMeters,
                minimumAccuracyMeters = it.exitPolicy.minimumAccuracyMeters,
                exitSeconds = it.exitPolicy.outsideConfirmationSeconds,
            )
        }
    }

    // The walking routes an administrator recorded, joined into one walkway network.
    val planner = remember(campusMap?.walkingRoutes) {
        WalkwayPlanner(campusMap?.walkingRoutes.orEmpty().mapNotNull { it.toWalkway() })
    }

    // Where the visitor stood when they were checked in, as a way back without gate pins.
    var entryPoint by remember(visit.trackingAppointmentId) {
        mutableStateOf(preferences.entryPoint(visit.trackingAppointmentId))
    }
    LaunchedEffect(steady) {
        val here = steady?.takeIf { it.quality == PositionQuality.USED } ?: return@LaunchedEffect
        if (entryPoint != null || here.accuracyMeters > ENTRY_MAX_ACCURACY_METERS) return@LaunchedEffect
        val checkedIn = serverTimeMillis(visit.checkedInAt) ?: return@LaunchedEffect
        if (System.currentTimeMillis() + clockOffsetMillis - checkedIn > ENTRY_WINDOW_MILLIS) return@LaunchedEffect
        val point = here.point
        if (campusArea != null && !GeoMath.insideCampus(campusArea.boundary, point, campusArea.bufferMeters)) return@LaunchedEffect
        preferences.saveEntryPoint(visit.trackingAppointmentId, point)
        entryPoint = point
    }

    // Where to guide: the office, or once every stop is done (or on request) a gate.
    val destination = campusMap?.destination
    val allStopsDone = campusMap != null && destination == null
    var exitRequested by rememberSaveable(visit.trackingAppointmentId) { mutableStateOf(false) }
    val exitMode = exitRequested || allStopsDone
    val gates = campusMap?.gates.orEmpty()
    var chosenGate by rememberSaveable(visit.trackingAppointmentId, exitMode) { mutableStateOf<String?>(null) }
    LaunchedEffect(exitMode, steady != null, gates, planner) {
        val from = steady?.point ?: return@LaunchedEffect
        if (exitMode && (chosenGate == null || gates.none { it.name == chosenGate })) {
            // The gate nearest on foot along the walkways; a gate no walkway reaches counts as
            // farther than it looks, since the way there is unknown.
            chosenGate = gates.minByOrNull { gate ->
                val point = GeoPoint(gate.latitude, gate.longitude)
                planner.plan(from, point)?.lengthMeters
                    ?: (GeoMath.distanceMeters(from, point) * WalkwayPlanner.OFF_WALKWAY_FACTOR)
            }?.name
        }
    }
    val target = remember(destination, exitMode, exitRequested, allStopsDone, chosenGate, gates, entryPoint, campusMap?.stops) {
        guidanceTarget(destination, campusMap?.stops.orEmpty(), exitMode, exitRequested, chosenGate, gates, entryPoint)
    }

    var snapshot by remember { mutableStateOf(GuidanceSnapshot(GuidancePhase.WAITING_FOR_FIX)) }
    val arrivalRule = remember(campusMap?.arrivalDistanceMeters, campusMap?.arrivalMaxAccuracyMeters) {
        ArrivalRule(campusMap?.arrivalDistanceMeters ?: 3.0, campusMap?.arrivalMaxAccuracyMeters ?: 8.0)
    }
    fun applyGuidance(update: GuidanceUpdate) {
        snapshot = update.snapshot
        if (voiceOn && voiceStatus == VoiceGuide.Status.READY) update.prompts.forEach { voice.speak(it.text, it.urgent) }
        val arrival = update.arrival ?: return
        // Only a new arrival buzzes, never one already on record when the screen opens.
        haptics.performHapticFeedback(HapticFeedbackType.LongPress)
        val stopId = arrival.target.appointmentId ?: return
        onArrival(stopId, if (arrival.method == ArrivalMethod.GPS) "gps" else "visitor", arrival.distanceMeters, arrival.accuracyMeters)
    }
    // The way to walk along the recorded walkways, planned again from every new position.
    var walkingPath by remember { mutableStateOf<WalkingPath?>(null) }
    // Where the arrow is drawn: on the blue line while the visitor is following it.
    var shownPoint by remember { mutableStateOf<GeoPoint?>(null) }
    // The arrow for the map: the latest reading, moved to where the arrow is drawn. It only
    // changes with a reading that was used, so the arrow greys out when none comes for a while.
    var shownLocation by remember { mutableStateOf<Location?>(null) }
    var shownAt by remember { mutableLongStateOf(Long.MIN_VALUE) }
    val follower = remember(visit.trackingAppointmentId) { PathFollower() }
    LaunchedEffect(live, target, campusArea, unit, arrivalRule, planner) {
        val raw = live?.reading
        val reading = raw?.toFix()
        val here = live?.steady
        val goal = target?.point
        val planned = if (here != null && goal != null) planner.plan(here.point, goal, walkingPath, target?.officeCode) else null
        val onPath = here != null && follower.follows(planned, here.accuracyMeters, here.elapsedMillis)
        val path = if (onPath) planned?.onWalkway() else planned
        walkingPath = path
        val point = if (onPath && path != null) path.points.first() else here?.point
        if (point == null || raw == null || here == null) {
            shownLocation = null
        } else if (shownLocation == null || point != shownPoint || here.elapsedMillis != shownAt) {
            shownLocation = Location(raw).apply {
                latitude = point.latitude
                longitude = point.longitude
            }
            shownAt = here.elapsedMillis
        }
        shownPoint = point
        applyGuidance(
            engine.update(
                fix = guidanceFix(reading, here),
                compassHeading = headingSensor.heading.value?.toDouble(),
                target = target,
                campus = campusArea,
                unit = unit,
                arrival = arrivalRule,
                path = path,
                reading = reading,
            ),
        )
    }
    // "I'm here": the visitor confirms they reached the office, e.g. inside a building.
    val confirmHere: () -> Unit = {
        engine.confirmArrival(location?.toFix(), target, System.currentTimeMillis())?.let { applyGuidance(it) }
    }

    // Map camera: follows the visitor heading-up until they drag the map.
    var following by rememberSaveable { mutableStateOf(true) }
    var commandCount by remember { mutableIntStateOf(0) }
    var command by remember { mutableStateOf<MapCommandRequest?>(null) }
    fun send(next: MapCommand) {
        commandCount += 1
        command = MapCommandRequest(commandCount, next)
    }
    var mapBearing by remember { mutableFloatStateOf(0f) }
    var mapOffline by remember { mutableStateOf(false) }
    var topOverlayPx by remember { mutableIntStateOf(0) }

    val markers = remember(campusMap, exitMode, target, entryPoint) {
        mapMarkers(campusMap, destination, exitMode, target, entryPoint)
    }
    val showDirections = snapshot.phase == GuidancePhase.NAVIGATING || snapshot.phase == GuidancePhase.WEAK_SIGNAL ||
        snapshot.phase == GuidancePhase.VERY_CLOSE
    // The walking path when walkways lead there; otherwise the dotted pointer straight at it.
    val routeLine = walkingPath?.takeIf { showDirections }?.toRouteLine()
    val pointerTo = target?.point?.takeIf { showDirections && routeLine == null }
    val mapModel = remember(boundary, markers, pointerTo) { CampusMapModel(boundary, markers, pointerTo) }

    var showPass by rememberSaveable { mutableStateOf(false) }
    var confirmStopSharing by rememberSaveable { mutableStateOf(false) }

    val sheetState = rememberStandardBottomSheetState(initialValue = SheetValue.PartiallyExpanded, skipHiddenState = true)
    val scaffoldState = rememberBottomSheetScaffoldState(bottomSheetState = sheetState)
    BackHandler(enabled = sheetState.currentValue == SheetValue.Expanded) {
        scope.launch { sheetState.partialExpand() }
    }
    LaunchedEffect(notice) {
        if (notice != null) {
            scaffoldState.snackbarHostState.showSnackbar(notice)
            onDismissNotice()
        }
    }

    val navigationBottom = WindowInsets.navigationBars.asPaddingValues().calculateBottomPadding()
    val peekHeight = SHEET_PEEK + navigationBottom
    val peekPx = with(density) { peekHeight.roundToPx() }

    BottomSheetScaffold(
        scaffoldState = scaffoldState,
        sheetPeekHeight = peekHeight,
        sheetContainerColor = Color.White,
        sheetShadowElevation = 14.dp,
        sheetDragHandle = { BottomSheetDefaults.DragHandle(color = BorderSoft) },
        containerColor = AppBackground,
        sheetContent = {
            VisitSheet(
                visit = visit,
                campusMap = campusMap,
                snapshot = snapshot,
                target = target,
                unit = unit,
                sharing = sharing,
                exitMode = exitMode,
                exitRequested = exitRequested,
                allStopsDone = allStopsDone,
                voiceOn = voiceOn,
                voiceStatus = voiceStatus,
                keepScreenOn = keepScreenOn,
                clockOffsetMillis = clockOffsetMillis,
                arrivalRule = arrivalRule,
                onShowPass = { showPass = true },
                onToggleVoice = {
                    voiceOn = !voiceOn
                    preferences.voiceEnabled = voiceOn
                    if (voiceOn) voice.speak("Voice directions on.", urgent = true) else voice.silence()
                },
                onUnitChange = {
                    unit = it
                    preferences.unit = it
                },
                onKeepScreenOnChange = {
                    keepScreenOn = it
                    preferences.keepScreenOn = it
                },
                onToggleExit = {
                    exitRequested = !exitRequested
                    following = true
                },
                onStopSharing = { confirmStopSharing = true },
                onShareAgain = onShareAgain,
                onRequestLocationPermission = onRequestLocationPermission,
            )
        },
    ) { _ ->
        Box(Modifier.fillMaxSize()) {
            CampusMap(
                model = mapModel,
                location = shownLocation,
                headingSensor = headingSensor,
                following = following,
                command = command,
                topPaddingPx = topOverlayPx,
                bottomPaddingPx = peekPx,
                onFollowDismissed = { following = false },
                onMarkerTapped = { scope.launch { sheetState.expand() } },
                onBearingChanged = { mapBearing = it },
                onOfflineChanged = { mapOffline = it },
                modifier = Modifier.fillMaxSize(),
                route = routeLine,
            )
            // Keeps the status bar icons readable over the map.
            Box(
                Modifier.fillMaxWidth().windowInsetsTopHeight(WindowInsets.statusBars)
                    .background(Color.White.copy(alpha = 0.9f)),
            )
            Column(
                modifier = Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 12.dp, vertical = 10.dp)
                    .onSizeChanged { topOverlayPx = it.height },
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Top) {
                    Surface(onClick = onBack, shape = CircleShape, color = Color.White, shadowElevation = 4.dp, modifier = Modifier.size(46.dp)) {
                        Box(contentAlignment = Alignment.Center) {
                            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back", tint = IsatuBlueDark)
                        }
                    }
                    GuidanceBanner(
                        snapshot = snapshot,
                        campusLoaded = campusMap != null,
                        permissionMissing = !hasPermission || sharing == SharingState.PERMISSION_NEEDED,
                        locationServicesOn = locationServicesOn,
                        allStopsDone = allStopsDone,
                        compass = compass,
                        mapBearing = mapBearing,
                        unit = unit,
                        exitSeconds = campusMap?.exitPolicy?.outsideConfirmationSeconds ?: 300,
                        onAllowLocation = onRequestLocationPermission,
                        onOpenLocationSettings = {
                            context.startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS))
                        },
                        onShowPass = if (visit.passPayload != null) ({ showPass = true }) else null,
                        onImHere = confirmHere,
                        modifier = Modifier.weight(1f),
                    )
                }
                if (needsCalibration && hasPermission) {
                    HintChip(Icons.Rounded.Explore, "Compass needs calibrating: wave your phone in a figure 8.")
                }
                if (mapOffline) {
                    HintChip(Icons.Rounded.CloudOff, "Map images can't load. Directions still work.", "Retry") {
                        send(MapCommand.RetryOnlineMap)
                    }
                }
                if (campusMap == null) {
                    HintChip(Icons.Rounded.CloudOff, "Couldn't load the campus map yet.", "Retry", onRefreshCampusMap)
                }
                if (voiceOn && volumeMuted && voiceStatus == VoiceGuide.Status.READY) {
                    HintChip(Icons.AutoMirrored.Rounded.VolumeOff, "Media volume is off. Turn it up to hear directions.")
                }
            }
            Column(
                modifier = Modifier.align(Alignment.BottomEnd).padding(end = 14.dp, bottom = peekHeight + 14.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                AnimatedVisibility(visible = !following && mapBearing != 0f) {
                    MapButton(Icons.Rounded.Explore, "Point the map north", rotation = -mapBearing) {
                        send(MapCommand.NorthUp)
                    }
                }
                MapButton(Icons.Rounded.ZoomOutMap, "Show the whole route") {
                    following = false
                    val points = walkingPath?.points ?: listOfNotNull(shownPoint, target?.point)
                    send(MapCommand.Overview(if (points.isEmpty()) boundary else points))
                }
                AnimatedVisibility(visible = !following) {
                    MapButton(Icons.Rounded.MyLocation, "Follow my position", highlighted = true) { following = true }
                }
            }
        }
    }

    if (showPass && visit.passPayload != null) {
        PassDialog(visit.passPayload, visit.visitorName, visit.reference) { showPass = false }
    }
    if (confirmStopSharing) {
        AlertDialog(
            onDismissRequest = { confirmStopSharing = false },
            title = { Text("Stop sharing your location?") },
            text = {
                Text(
                    "Security will no longer see where you are on campus. Your visit stays active, and " +
                        "you still scan your pass at the gate when you leave. Directions on this phone keep " +
                        "working, and you can share again from this screen.",
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmStopSharing = false
                    onStopSharing()
                }) { Text("Stop sharing", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmStopSharing = false }) { Text("Keep sharing") } },
        )
    }
}

/** The top banner: what to do next, like the instruction bar of a driver app. */
@Composable
private fun GuidanceBanner(
    snapshot: GuidanceSnapshot,
    campusLoaded: Boolean,
    permissionMissing: Boolean,
    locationServicesOn: Boolean,
    allStopsDone: Boolean,
    compass: Float?,
    mapBearing: Float,
    unit: DistanceUnit,
    exitSeconds: Int,
    onAllowLocation: () -> Unit,
    onOpenLocationSettings: () -> Unit,
    onShowPass: (() -> Unit)?,
    onImHere: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val target = snapshot.target
    val distance = snapshot.distanceMeters?.let { displayDistance(it, unit) }
    val accuracy = snapshot.accuracyMeters?.let { displayAccuracy(it, unit) }
    // "I'm here" when close, or wherever GPS can't confirm the spot (indoors, no pin).
    val canConfirm = target != null && when (snapshot.phase) {
        GuidancePhase.VERY_CLOSE, GuidancePhase.WEAK_SIGNAL, GuidancePhase.NO_PIN -> true
        GuidancePhase.NAVIGATING -> (snapshot.distanceMeters ?: Double.MAX_VALUE) <= CONFIRM_WITHIN_METERS
        else -> false
    }
    val tone = when {
        permissionMissing || !locationServicesOn -> BannerSlate
        snapshot.phase == GuidancePhase.OUTSIDE_CAMPUS -> BannerOrange
        snapshot.phase == GuidancePhase.ARRIVED -> IsatuGreen
        snapshot.phase == GuidancePhase.VERY_CLOSE -> BannerTeal
        snapshot.phase == GuidancePhase.WEAK_SIGNAL || snapshot.phase == GuidancePhase.WAITING_FOR_FIX -> BannerSlate
        else -> IsatuBlueDark
    }
    val background by animateColorAsState(tone, label = "bannerColor")
    Surface(
        modifier = modifier.semantics { liveRegion = LiveRegionMode.Polite },
        shape = RoundedCornerShape(18.dp),
        color = background,
        contentColor = Color.White,
        shadowElevation = 6.dp,
    ) {
        Column(Modifier.padding(horizontal = 14.dp, vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            when {
                permissionMissing -> {
                    BannerHeader(Icons.Rounded.LocationOff, "Location is off for this app")
                    BannerText("Allow precise location to see where you are and get directions.")
                    BannerButton("Allow location", onAllowLocation)
                }
                !locationServicesOn -> {
                    BannerHeader(Icons.Rounded.LocationOff, "Turn on your phone's Location")
                    BannerText("Directions need your phone's location setting.")
                    BannerButton("Open settings", onOpenLocationSettings)
                }
                !campusLoaded -> {
                    BannerHeader(null, "Loading the campus map…", progress = true)
                }
                else -> when (snapshot.phase) {
                    GuidancePhase.WAITING_FOR_FIX -> {
                        BannerHeader(null, "Finding your position…", progress = true)
                        BannerText("Stay where you can see the sky for a moment.")
                    }
                    GuidancePhase.WEAK_SIGNAL -> {
                        BannerHeader(Icons.Rounded.GpsNotFixed, "Weak GPS signal")
                        BannerText(
                            buildString {
                                accuracy?.let { append("Your position is only accurate to $it here, so directions are paused. ") }
                                append("They resume when your position is clearer.")
                            },
                        )
                        if (canConfirm) BannerButton("I'm here", onImHere)
                    }
                    GuidancePhase.NO_PIN -> {
                        BannerHeader(Icons.Rounded.Info, target?.name ?: "Your office")
                        BannerText("Not on the campus map yet. Ask a guard for directions.")
                        target?.detail?.takeIf { it.isNotBlank() }?.let { BannerText("Location: $it", strong = true) }
                        if (canConfirm) BannerButton("I'm here", onImHere)
                    }
                    GuidancePhase.VERY_CLOSE -> {
                        val within = max(snapshot.distanceMeters ?: 0.0, snapshot.accuracyMeters ?: 0.0)
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            snapshot.bearingDegrees?.let { DirectionArrow(it, compass, mapBearing) }
                            Text("You're very close", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                        }
                        BannerText(
                            "${target?.name ?: "Your office"} is within about ${displayDistance(within, unit)}. " +
                                "GPS can't pinpoint the exact spot here, so look for the entrance or a sign.",
                        )
                        target?.detail?.takeIf { it.isNotBlank() }?.let { BannerText("Location: $it", strong = true) }
                        BannerButton("I'm here", onImHere)
                    }
                    GuidancePhase.NO_TARGET -> {
                        BannerHeader(Icons.Rounded.Flag, if (allStopsDone) "All office stops done" else "Head to the gate")
                        BannerText("Go back to the gate and show your pass to the guard to check out.")
                        onShowPass?.let { BannerButton("Show pass", it) }
                    }
                    GuidancePhase.ARRIVED -> {
                        BannerHeader(Icons.Rounded.CheckCircle, "You've arrived")
                        BannerText(target?.name.orEmpty(), strong = true)
                        target?.detail?.takeIf { it.isNotBlank() }?.let { BannerText(it) }
                        when (snapshot.arrivedBy) {
                            ArrivalMethod.GPS -> BannerText("Confirmed by GPS at the office pin.")
                            ArrivalMethod.VISITOR -> BannerText("Confirmed by you.")
                            null -> Unit
                        }
                        if (target != null && target.kind != TargetKind.OFFICE) {
                            BannerText("Show your pass to the guard to check out.")
                            onShowPass?.let { BannerButton("Show pass", it) }
                        }
                    }
                    GuidancePhase.OUTSIDE_CAMPUS -> {
                        BannerHeader(Icons.Rounded.Warning, "You're outside the campus")
                        OutsideCountdown(snapshot.outsideSinceMillis, exitSeconds)
                    }
                    GuidancePhase.NAVIGATING -> {
                        val route = snapshot.route
                        if (route != null) {
                            RouteNavigatingContent(snapshot, route, accuracy, unit, compass, mapBearing)
                        } else {
                            NavigatingContent(snapshot, distance, accuracy, compass, mapBearing)
                        }
                        if (canConfirm) BannerButton("I'm here", onImHere)
                    }
                }
            }
        }
    }
}

/** The turning arrow that points at the office, from where the phone faces or as drawn on the map. */
@Composable
private fun DirectionArrow(bearing: Double, compass: Float?, mapBearing: Float) {
    val reference = compass ?: mapBearing
    val rotation = rememberSmoothAngle((bearing - reference).toFloat())
    Box(
        Modifier.size(52.dp).background(Color.White.copy(alpha = 0.16f), CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            Icons.Rounded.Navigation,
            contentDescription = null,
            tint = Color.White,
            modifier = Modifier.size(34.dp).rotate(rotation),
        )
    }
}

@Composable
private fun NavigatingContent(snapshot: GuidanceSnapshot, distance: String?, accuracy: String?, compass: Float?, mapBearing: Float) {
    val target = snapshot.target ?: return
    val bearing = snapshot.bearingDegrees
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        if (bearing != null) DirectionArrow(bearing, compass, mapBearing)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    distance ?: "",
                    fontSize = 26.sp,
                    fontWeight = FontWeight.Bold,
                    style = MaterialTheme.typography.headlineSmall,
                )
                // How sure GPS is right now, so a distance is never shown as more exact than it is.
                accuracy?.let {
                    Text(
                        "GPS $it",
                        style = MaterialTheme.typography.labelMedium,
                        color = Color.White.copy(alpha = 0.8f),
                        modifier = Modifier.padding(bottom = 4.dp),
                    )
                }
            }
            Text(
                listOfNotNull(target.name, snapshot.side?.let(::sideWords)).joinToString(" · "),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            if (target.detail.isNotBlank()) {
                Text(
                    target.detail,
                    style = MaterialTheme.typography.bodySmall,
                    color = Color.White.copy(alpha = 0.85f),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}

/**
 * Along a recorded walkway: what to do next ("Turn left in 20 m"), the walking distance left,
 * and how sure GPS is. The arrow points along the path, so it turns before each corner.
 */
@Composable
private fun RouteNavigatingContent(
    snapshot: GuidanceSnapshot,
    route: RouteGuidance,
    accuracy: String?,
    unit: DistanceUnit,
    compass: Float?,
    mapBearing: Float,
) {
    val target = snapshot.target ?: return
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        snapshot.bearingDegrees?.let { DirectionArrow(it, compass, mapBearing) }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
            Text(
                routeInstruction(route, unit),
                fontSize = 21.sp,
                fontWeight = FontWeight.Bold,
                style = MaterialTheme.typography.titleLarge,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    "${displayDistance(route.remainingMeters, unit)} to ${target.name}",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                // How sure GPS is right now, so a distance is never shown as more exact than it is.
                accuracy?.let {
                    Text("GPS $it", style = MaterialTheme.typography.labelMedium, color = Color.White.copy(alpha = 0.8f))
                }
            }
            if (target.detail.isNotBlank()) {
                Text(
                    target.detail,
                    style = MaterialTheme.typography.bodySmall,
                    color = Color.White.copy(alpha = 0.85f),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}

@Composable
private fun OutsideCountdown(outsideSinceMillis: Long?, exitSeconds: Int) {
    val now = rememberNow()
    val remaining = outsideSinceMillis?.let { exitSeconds - ((now - it) / 1000).toInt() }
    BannerText(
        if (remaining != null && remaining > 0) {
            "Go back inside within about ${formatClock(remaining)} or your visit ends automatically. " +
                "Your position isn't shared while you're outside."
        } else {
            "Your visit ends automatically because you left the campus."
        },
    )
}

@Composable
private fun BannerHeader(icon: androidx.compose.ui.graphics.vector.ImageVector?, title: String, progress: Boolean = false) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (progress) {
            CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
        } else if (icon != null) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(20.dp))
        }
        Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun BannerText(text: String, strong: Boolean = false) {
    Text(
        text,
        style = MaterialTheme.typography.bodyMedium,
        fontWeight = if (strong) FontWeight.SemiBold else FontWeight.Normal,
        color = if (strong) Color.White else Color.White.copy(alpha = 0.9f),
    )
}

@Composable
private fun BannerButton(label: String, onClick: () -> Unit) {
    Button(
        onClick = onClick,
        colors = ButtonDefaults.buttonColors(containerColor = Color.White, contentColor = IsatuBlueDark),
        shape = RoundedCornerShape(12.dp),
    ) { Text(label, fontWeight = FontWeight.SemiBold) }
}

@Composable
private fun HintChip(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    text: String,
    action: String? = null,
    onAction: (() -> Unit)? = null,
) {
    Surface(shape = RoundedCornerShape(14.dp), color = Color.White, shadowElevation = 3.dp) {
        Row(
            Modifier.padding(start = 12.dp, end = if (action != null) 4.dp else 12.dp, top = 6.dp, bottom = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Icon(icon, contentDescription = null, tint = IsatuOrange, modifier = Modifier.size(18.dp))
            Text(text, style = MaterialTheme.typography.bodySmall, color = Ink, modifier = Modifier.weight(1f, fill = false))
            if (action != null && onAction != null) {
                TextButton(onClick = onAction) { Text(action, fontWeight = FontWeight.SemiBold) }
            }
        }
    }
}

@Composable
private fun MapButton(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    description: String,
    rotation: Float = 0f,
    highlighted: Boolean = false,
    onClick: () -> Unit,
) {
    SmallFloatingActionButton(
        onClick = onClick,
        containerColor = if (highlighted) IsatuBlue else Color.White,
        contentColor = if (highlighted) Color.White else IsatuBlueDark,
        modifier = Modifier.size(48.dp),
    ) {
        Icon(icon, contentDescription = description, modifier = Modifier.rotate(rotation))
    }
}

/** The pull-up panel: time and distance, the pass, voice, the visit, and sharing. */
@Composable
private fun VisitSheet(
    visit: CampusVisit,
    campusMap: CampusMapData?,
    snapshot: GuidanceSnapshot,
    target: GuidanceTarget?,
    unit: DistanceUnit,
    sharing: SharingState,
    exitMode: Boolean,
    exitRequested: Boolean,
    allStopsDone: Boolean,
    voiceOn: Boolean,
    voiceStatus: VoiceGuide.Status,
    keepScreenOn: Boolean,
    clockOffsetMillis: Long,
    arrivalRule: ArrivalRule,
    onShowPass: () -> Unit,
    onToggleVoice: () -> Unit,
    onUnitChange: (DistanceUnit) -> Unit,
    onKeepScreenOnChange: (Boolean) -> Unit,
    onToggleExit: () -> Unit,
    onStopSharing: () -> Unit,
    onShareAgain: () -> Unit,
    onRequestLocationPermission: () -> Unit,
) {
    Column(
        Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp)
            .navigationBarsPadding().padding(bottom = 18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        // Peek area: what a glance should answer.
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                val distance = snapshot.distanceMeters
                val route = snapshot.route
                val headline = when {
                    snapshot.phase == GuidancePhase.ARRIVED -> "Arrived"
                    snapshot.phase == GuidancePhase.VERY_CLOSE -> "Very close"
                    route != null && snapshot.phase == GuidancePhase.NAVIGATING ->
                        "${walkingMinutesAlongPath(route.remainingMeters)} min walk · ${displayDistance(route.remainingMeters, unit)}"
                    distance != null && snapshot.phase == GuidancePhase.NAVIGATING ->
                        "${walkingMinutes(distance)} min walk · ${displayDistance(distance, unit)}"
                    else -> if (exitMode) "Heading out" else "Campus visit"
                }
                Text(headline, style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark, fontWeight = FontWeight.Bold)
                Text(
                    target?.name ?: if (allStopsDone) "All office stops done" else "Your visit",
                    style = MaterialTheme.typography.bodyLarge,
                    color = Ink,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                target?.detail?.takeIf { it.isNotBlank() }?.let {
                    Text(it, style = MaterialTheme.typography.bodySmall, color = MutedInk, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
            SharingBadge(sharing)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
            if (visit.passPayload != null) {
                Button(
                    onClick = onShowPass,
                    modifier = Modifier.weight(1f).height(48.dp),
                    shape = RoundedCornerShape(12.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = IsatuBlue),
                ) {
                    Icon(Icons.Rounded.QrCode2, contentDescription = null, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text("Show pass", fontWeight = FontWeight.SemiBold)
                }
            }
            OutlinedButton(
                onClick = onToggleVoice,
                enabled = voiceStatus != VoiceGuide.Status.UNAVAILABLE,
                modifier = Modifier.height(48.dp),
                shape = RoundedCornerShape(12.dp),
                border = BorderStroke(1.dp, BorderSoft),
            ) {
                Icon(
                    if (voiceOn) Icons.AutoMirrored.Rounded.VolumeUp else Icons.AutoMirrored.Rounded.VolumeOff,
                    contentDescription = if (voiceOn) "Turn voice directions off" else "Turn voice directions on",
                    tint = IsatuBlueDark,
                )
            }
            if (!allStopsDone) {
                OutlinedButton(
                    onClick = onToggleExit,
                    modifier = Modifier.height(48.dp),
                    shape = RoundedCornerShape(12.dp),
                    border = BorderStroke(1.dp, BorderSoft),
                ) {
                    Icon(
                        if (exitRequested) Icons.AutoMirrored.Rounded.DirectionsWalk else Icons.AutoMirrored.Rounded.ExitToApp,
                        contentDescription = if (exitRequested) "Guide me to my office" else "Guide me out",
                        tint = IsatuBlueDark,
                    )
                }
            }
        }

        HorizontalDivider(color = BorderSoft)
        SheetSection("Your visit") {
            VisitTimes(visit, clockOffsetMillis)
            visit.reference?.let { SheetLine("Reference", it) }
        }
        if (visit.stops.size > 1) {
            SheetSection("Office stops") { StopProgress(visit.stops, visit.currentStopId, campusMap?.stops.orEmpty()) }
        }
        SheetSection("Directions") {
            Text(
                when {
                    snapshot.route != null && exitMode ->
                        "Follow the blue line to the way out. It's a walking route recorded by ISATU, so it keeps to " +
                            "the walkways. Turns are announced before you reach them."
                    snapshot.route != null ->
                        "Follow the blue line. It's a walking route recorded by ISATU, so it goes around buildings, " +
                            "not through them. Turns are announced before you reach them."
                    exitMode -> "Guiding you to the way out. The dotted line points straight at it; follow the walkways."
                    else -> "The dotted line points straight at your office. Follow walkways and signs; buildings may be in the way."
                },
                style = MaterialTheme.typography.bodySmall,
                color = MutedInk,
            )
            Text(
                "\"You have arrived\" is only said when GPS places you within " +
                    "${displayDistance(arrivalRule.distanceMeters, unit)} of the office pin with a clear signal " +
                    "(${displayAccuracy(arrivalRule.maxAccuracyMeters, unit)} or better). Inside buildings, " +
                    "where GPS can't confirm the spot, tap I'm here.",
                style = MaterialTheme.typography.bodySmall,
                color = MutedInk,
            )
            if (!allStopsDone) {
                TextButton(onClick = onToggleExit) {
                    Text(if (exitRequested) "Guide me back to my office" else "Guide me out to the gate")
                }
            }
            SettingRow("Voice directions", if (voiceStatus == VoiceGuide.Status.UNAVAILABLE) "Not available on this phone" else null) {
                Switch(checked = voiceOn && voiceStatus != VoiceGuide.Status.UNAVAILABLE, onCheckedChange = { onToggleVoice() }, enabled = voiceStatus != VoiceGuide.Status.UNAVAILABLE)
            }
            SettingRow("Distances") {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = unit == DistanceUnit.METERS, onClick = { onUnitChange(DistanceUnit.METERS) }, label = { Text("Meters") })
                    FilterChip(selected = unit == DistanceUnit.FEET, onClick = { onUnitChange(DistanceUnit.FEET) }, label = { Text("Feet") })
                }
            }
            SettingRow("Keep screen on", "While this map is open") {
                Switch(checked = keepScreenOn, onCheckedChange = onKeepScreenOnChange)
            }
        }
        SheetSection("Location sharing") {
            Text(
                when (sharing) {
                    SharingState.SHARING -> "ISATU Security sees your position only while you're inside the campus. It stops when your visit ends."
                    SharingState.STARTING -> "Starting secure location sharing…"
                    SharingState.OFF -> "You stopped sharing your location. Security can't see where you are."
                    SharingState.PERMISSION_NEEDED -> "Precise location is off for this app, so nothing is shared and directions can't start."
                },
                style = MaterialTheme.typography.bodyMedium,
                color = MutedInk,
            )
            when (sharing) {
                SharingState.SHARING, SharingState.STARTING -> TextButton(onClick = onStopSharing) {
                    Text("Stop sharing my location", color = MaterialTheme.colorScheme.error)
                }
                SharingState.OFF -> OutlinedButton(onClick = onShareAgain, shape = RoundedCornerShape(12.dp)) {
                    Icon(Icons.Rounded.LocationOn, contentDescription = null, tint = IsatuBlue)
                    Spacer(Modifier.width(6.dp))
                    Text("Share my location again")
                }
                SharingState.PERMISSION_NEEDED -> OutlinedButton(onClick = onRequestLocationPermission, shape = RoundedCornerShape(12.dp)) {
                    Text("Allow location")
                }
            }
        }
    }
}

@Composable
private fun SheetSection(title: String, content: @Composable ColumnScope.() -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(title, style = MaterialTheme.typography.titleMedium, color = IsatuBlueDark)
        content()
    }
}

@Composable
private fun SheetLine(label: String, value: String) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        Spacer(Modifier.width(12.dp))
        Text(value, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.End)
    }
}

@Composable
private fun SettingRow(label: String, detail: String? = null, control: @Composable () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.bodyLarge)
            detail?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MutedInk) }
        }
        control()
    }
}

@Composable
private fun SharingBadge(sharing: SharingState) {
    val (label, color) = when (sharing) {
        SharingState.SHARING -> "Sharing" to IsatuGreen
        SharingState.STARTING -> "Starting" to IsatuOrange
        SharingState.OFF -> "Not sharing" to MutedInk
        SharingState.PERMISSION_NEEDED -> "Location off" to MaterialTheme.colorScheme.error
    }
    Surface(shape = RoundedCornerShape(50), color = color.copy(alpha = 0.12f)) {
        Text(
            label,
            modifier = Modifier.padding(horizontal = 11.dp, vertical = 7.dp),
            color = color,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
        )
    }
}

/** Check-in time, live time on campus, and the booked slot of the current stop. */
@Composable
private fun VisitTimes(visit: CampusVisit, clockOffsetMillis: Long) {
    val now = rememberNow() + clockOffsetMillis
    val checkedIn = serverTimeMillis(visit.checkedInAt)
    visit.checkedInAt?.let { SheetLine("Checked in", formatTimeOfDay(it)) }
    if (checkedIn != null && now >= checkedIn) SheetLine("Time on campus", formatDuration(now - checkedIn))
    val slot = visit.stops.firstOrNull { it.id == visit.currentStopId } ?: visit.appointment
    if (slot != null && slot.visitType != "walk_in" && slot.scheduledStartAt.isNotBlank() && slot.scheduledEndAt.isNotBlank()) {
        SheetLine("Your slot", formatTimeSpan(slot.scheduledStartAt, slot.scheduledEndAt))
        serverTimeMillis(slot.scheduledEndAt)?.let { end ->
            val note = if (now < end) {
                "Ends in ${formatDuration(end - now)}"
            } else {
                "Ended ${formatDuration(now - end)} ago. When you're done, scan your pass at the gate."
            }
            Text(note, style = MaterialTheme.typography.bodySmall, color = if (now < end) MutedInk else IsatuOrange)
        }
    }
}

/** A visit's offices in order, with each stop's state. */
@Composable
private fun StopProgress(stops: List<AppointmentDto>, currentStopId: Long?, places: List<CampusStopDto>) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        stops.forEachIndexed { index, stop ->
            val current = stop.id == currentStopId
            val done = stop.status in setOf("completed", "window_closed")
            val inactive = stop.status in setOf("cancelled", "rejected", "unanswered")
            val place = places.firstOrNull { it.appointmentId == stop.id }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(
                    Modifier.size(26.dp).background(
                        when {
                            current -> IsatuBlue
                            done -> IsatuGreen
                            else -> BorderSoft
                        },
                        CircleShape,
                    ),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        "${index + 1}",
                        color = if (current || done) Color.White else MutedInk,
                        style = MaterialTheme.typography.labelMedium,
                    )
                }
                Column(Modifier.weight(1f)) {
                    Text(
                        stop.office.name,
                        fontWeight = if (current) FontWeight.SemiBold else FontWeight.Normal,
                        color = if (inactive) MutedInk else Ink,
                    )
                    Text(
                        listOfNotNull(
                            if (stop.visitType == "walk_in") "Walk-in" else formatTimeOfDay(stop.scheduledStartAt),
                            stopProgressLabel(stop.status, current),
                            place?.location?.takeIf { it.isNotBlank() },
                        ).joinToString(" • "),
                        style = MaterialTheme.typography.bodySmall,
                        color = MutedInk,
                    )
                }
            }
        }
    }
}

private fun stopProgressLabel(status: String, current: Boolean): String = when {
    current -> "Now"
    status == "checked_in" -> "Up next"
    status == "completed" -> "Done"
    else -> statusLabel(status)
}

/** The pass, large and at full screen brightness, for the check-out scan at the gate. */
@Composable
private fun PassDialog(payload: String, visitorName: String, reference: String?, onDismiss: () -> Unit) {
    val bitmap = remember(payload) { generateQrBitmap(payload) }
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        val window = (LocalView.current.parent as? DialogWindowProvider)?.window
        DisposableEffect(window) {
            window?.let {
                val attributes = it.attributes
                attributes.screenBrightness = 1f
                it.attributes = attributes
            }
            onDispose { }
        }
        Surface(
            modifier = Modifier.fillMaxWidth().padding(20.dp),
            shape = RoundedCornerShape(24.dp),
            color = Color.White,
        ) {
            Column(
                Modifier.padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Text("Show this to the guard", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
                Text(
                    "Scanning your pass at the gate checks you out and stops location sharing.",
                    color = MutedInk,
                    textAlign = TextAlign.Center,
                    style = MaterialTheme.typography.bodyMedium,
                )
                bitmap?.let { Image(it.asImageBitmap(), "Visitor QR pass", Modifier.size(270.dp)) }
                Text(visitorName, style = MaterialTheme.typography.titleMedium)
                reference?.let { Text("Reference $it", color = MutedInk) }
                Button(
                    onClick = onDismiss,
                    modifier = Modifier.fillMaxWidth().height(50.dp),
                    shape = RoundedCornerShape(12.dp),
                    colors = ButtonDefaults.buttonColors(containerColor = IsatuBlue),
                ) { Text("Done", fontWeight = FontWeight.SemiBold) }
            }
        }
    }
}

/** Builds the guidance target for the office, a gate, or the check-in point. */
private fun guidanceTarget(
    destination: CampusStopDto?,
    stops: List<CampusStopDto>,
    exitMode: Boolean,
    exitRequested: Boolean,
    chosenGate: String?,
    gates: List<CampusGateDto>,
    entryPoint: GeoPoint?,
): GuidanceTarget? {
    if (exitMode) {
        val opening = if (exitRequested) "" else "All your office stops are done. "
        val gate = gates.firstOrNull { it.name == chosenGate } ?: gates.singleOrNull()
        return when {
            gate != null -> GuidanceTarget(
                key = "gate:${gate.name}",
                name = gate.name,
                kind = TargetKind.GATE,
                point = GeoPoint(gate.latitude, gate.longitude),
                startLine = "${opening}Head to ${gate.name}",
            )
            entryPoint != null -> GuidanceTarget(
                key = "entry",
                name = "Your check-in point",
                kind = TargetKind.ENTRY,
                point = entryPoint,
                startLine = "${opening}Head back to where you checked in",
            )
            else -> null
        }
    }
    destination ?: return null
    val point = if (destination.latitude != null && destination.longitude != null) {
        GeoPoint(destination.latitude, destination.longitude)
    } else {
        null
    }
    val earlierStopDone = stops.takeWhile { it.appointmentId != destination.appointmentId }.any { it.status == "completed" }
    return GuidanceTarget(
        key = "office:${destination.appointmentId}:${destination.officeCode}",
        name = destination.label,
        kind = TargetKind.OFFICE,
        point = point,
        detail = destination.location,
        startLine = if (earlierStopDone) "Next stop: ${destination.label}" else "Heading to ${destination.label}",
        appointmentId = destination.appointmentId,
        arrivedBy = when {
            destination.arrivedAt == null -> null
            destination.arrivalMethod == "visitor" -> ArrivalMethod.VISITOR
            else -> ArrivalMethod.GPS
        },
        officeCode = destination.officeCode,
    )
}

private fun mapMarkers(
    campusMap: CampusMapData?,
    destination: CampusStopDto?,
    exitMode: Boolean,
    target: GuidanceTarget?,
    entryPoint: GeoPoint?,
): List<MapMarker> {
    if (campusMap == null) return emptyList()
    return buildList {
        if (campusMap.stops.size > 1) {
            campusMap.stops.forEachIndexed { index, stop ->
                val latitude = stop.latitude
                val longitude = stop.longitude
                if (latitude != null && longitude != null && (exitMode || stop.appointmentId != destination?.appointmentId)) {
                    add(
                        MapMarker(
                            "stop:${stop.appointmentId}",
                            GeoPoint(latitude, longitude),
                            MarkerStyle.Stop(index + 1, done = stop.status in setOf("completed", "window_closed")),
                        ),
                    )
                }
            }
        }
        campusMap.gates.forEach { gate ->
            add(MapMarker("gate:${gate.name}", GeoPoint(gate.latitude, gate.longitude), MarkerStyle.Pin(gate.name, GateGreen)))
        }
        if (exitMode && campusMap.gates.isEmpty() && entryPoint != null) {
            add(MapMarker("entry", entryPoint, MarkerStyle.Pin("Check-in point", GateGreen)))
        }
        val point = target?.point
        if (!exitMode && target != null && point != null) {
            add(MapMarker(target.key, point, MarkerStyle.Pin(target.name, OfficeRed)))
        }
    }
}

/** Turns toward [degrees] the short way round, smoothly. */
@Composable
private fun rememberSmoothAngle(degrees: Float): Float {
    val unwrapped = remember { FloatArray(1) { degrees } }
    val step = ((degrees - unwrapped[0]) % 360 + 540) % 360 - 180
    unwrapped[0] += step
    val animated by animateFloatAsState(unwrapped[0], tween(durationMillis = 250), label = "pointer")
    return animated
}

/** The current time, updated every second while shown. */
@Composable
private fun rememberNow(): Long {
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(Unit) {
        while (true) {
            now = System.currentTimeMillis()
            delay(1_000)
        }
    }
    return now
}

/** A recorded route as the planner uses it, or null when it has fewer than two points. */
private fun WalkingRouteDto.toWalkway(): Walkway? {
    val line = points.mapNotNull { pair -> if (pair.size >= 2) GeoPoint(pair[0], pair[1]) else null }
    return if (line.size >= 2) Walkway(id, officeCode, name, line) else null
}

/** The part along walkways as a solid line; the straight legs onto and off them dotted. */
private fun WalkingPath.toRouteLine(): RouteLine {
    val walkway = points.subList(walkwayStart, walkwayEnd + 1).toList()
    val connectors = buildList {
        if (joinMeters >= CONNECTOR_MIN_METERS) add(points.subList(0, walkwayStart + 1).toList())
        if (finishMeters >= CONNECTOR_MIN_METERS) add(points.subList(walkwayEnd, points.size).toList())
    }
    return RouteLine(walkway, connectors)
}

private fun Location.toFix() = LocationFix(
    point = GeoPoint(latitude, longitude),
    accuracyMeters = if (hasAccuracy()) accuracy.toDouble() else null,
    timeMillis = System.currentTimeMillis(),
    speedMetersPerSecond = if (hasSpeed()) speed.toDouble() else null,
    courseDegrees = if (hasBearing()) bearing.toDouble() else null,
)

/**
 * The position directions are worked out from: the steady one, with the accuracy GPS itself
 * reported, so the banner never claims more than GPS knows. A reading that was left out
 * (too weak, or an impossible jump) keeps the last good position and lends it no speed or
 * course. Before there is a steady position, a weak reading is passed as it is, so the
 * weak-signal notice still shows.
 */
private fun guidanceFix(reading: LocationFix?, steady: SteadyPosition?): LocationFix? = when {
    reading == null -> null
    steady == null -> reading.takeIf { (it.accuracyMeters ?: 0.0) > GuidanceEngine.WEAK_SIGNAL_METERS }
    steady.quality == PositionQuality.USED -> reading.copy(point = steady.point)
    steady.quality == PositionQuality.HELD_WEAK ->
        reading.copy(point = steady.point, speedMetersPerSecond = null, courseDegrees = null)
    else -> reading.copy(
        point = steady.point,
        accuracyMeters = steady.accuracyMeters,
        speedMetersPerSecond = null,
        courseDegrees = null,
    )
}

/**
 * Whether location sharing is on: from the permission, the server's tracking session,
 * and a restart the visitor just asked for.
 */
fun sharingState(permissionMissing: Boolean, tracking: TrackingData?, resuming: Boolean): SharingState = when {
    permissionMissing -> SharingState.PERMISSION_NEEDED
    tracking?.session?.active == true -> SharingState.SHARING
    tracking?.session?.endedReason in setOf("consent_withdrawn", "manual") && !resuming -> SharingState.OFF
    else -> SharingState.STARTING
}

/** "4:05" for a countdown in seconds. */
private fun formatClock(seconds: Int): String = String.format(Locale.US, "%d:%02d", seconds / 60, seconds % 60)

private val SHEET_PEEK = 196.dp
private const val ENTRY_MAX_ACCURACY_METERS = 30f
private const val ENTRY_WINDOW_MILLIS = 10 * 60_000L
private val BannerSlate = Color(0xFF334155)
private val BannerOrange = Color(0xFFC2410C)
private val BannerTeal = Color(0xFF0F766E)
/** "I'm here" is offered within this distance even while GPS still guides the visitor. */
private const val CONFIRM_WITHIN_METERS = 30.0
/** Straight legs onto and off a walkway shorter than this are not drawn. */
private const val CONNECTOR_MIN_METERS = 2.0
private val OfficeRed = Color(0xFFD92D20).toArgb()
private val GateGreen = Color(0xFF15803D).toArgb()
