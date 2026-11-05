(function () {
    if (!PhoneTrackerAuth.requireRole(["security", "admin"])) {
        return;
    }

    const role = PhoneTrackerAuth.getRole();
    const displayName = PhoneTrackerAuth.getDisplayName() || (role === "admin" ? "Administrator" : "Security Desk");
    const username = PhoneTrackerAuth.getUsername() || (role === "admin" ? "admin" : "security");
    const profileButton = document.getElementById("securityProfileBtn");
    const profileMenu = document.getElementById("securityProfileMenu");

    document.getElementById("securityProfileName").textContent = displayName;
    document.getElementById("securityProfileUsername").textContent = username;
    document.getElementById("securityRoleLabel").textContent = role === "admin" ? "Admin" : "Security";
    document.getElementById("securityAdminLink").hidden = role !== "admin";

    profileButton.addEventListener("click", function () {
        const willOpen = profileMenu.hidden;
        profileMenu.hidden = !willOpen;
        profileButton.setAttribute("aria-expanded", String(willOpen));
    });

    document.addEventListener("click", function (event) {
        if (!profileMenu.hidden && !profileMenu.contains(event.target) && !profileButton.contains(event.target)) {
            profileMenu.hidden = true;
            profileButton.setAttribute("aria-expanded", "false");
        }
    });

    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            window.location.href = "login.html";
        });
    });

    const readerId = "reader";
    const cameraMessage = document.getElementById("cameraMessage");
    const cameraState = document.getElementById("cameraState");
    const cameraStateText = cameraState.querySelector("span");
    const cameraPlaceholder = document.getElementById("cameraPlaceholder");
    const cameraPlaceholderTitle = document.getElementById("cameraPlaceholderTitle");
    const cameraPlaceholderText = document.getElementById("cameraPlaceholderText");
    const scannerGuide = document.querySelector(".security-scanner-guide");
    const cameraSelectField = document.getElementById("cameraSelectField");
    const cameraSelect = document.getElementById("cameraSelect");
    const retryCameraBtn = document.getElementById("retryCameraBtn");
    const scanQrPhotoBtn = document.getElementById("scanQrPhotoBtn");
    const qrPhotoInput = document.getElementById("qrPhotoInput");
    const chooseQrImageBtn = document.getElementById("chooseQrImageBtn");
    const qrImageInput = document.getElementById("qrImageInput");
    const manualEntry = document.getElementById("manualEntry");
    const manualForm = document.getElementById("manualForm");
    const tokenInput = document.getElementById("tokenInput");
    const submitTokenBtn = document.getElementById("submitTokenBtn");
    const scanResult = document.getElementById("scanResult");
    const scanResultLabel = document.getElementById("scanResultLabel");
    const scanResultTitle = document.getElementById("scanResultTitle");
    const scanResultText = document.getElementById("scanResultText");
    const scanResultDetails = document.getElementById("scanResultDetails");
    const scanVisitorName = document.getElementById("scanVisitorName");
    const scanDestination = document.getElementById("scanDestination");
    const scanScheduleRow = document.getElementById("scanScheduleRow");
    const scanSchedule = document.getElementById("scanSchedule");
    const scanWindowRow = document.getElementById("scanWindowRow");
    const scanWindow = document.getElementById("scanWindow");
    const scanTimeInRow = document.getElementById("scanTimeInRow");
    const scanTimeIn = document.getElementById("scanTimeIn");
    const openOverrideBtn = document.getElementById("openQrOverrideBtn");
    const scanNextBtn = document.getElementById("scanNextBtn");
    const overrideDialog = document.getElementById("qrOverrideDialog");
    const overrideForm = document.getElementById("qrOverrideForm");
    const overrideReason = document.getElementById("qrOverrideReason");
    const overrideMinutes = document.getElementById("qrOverrideMinutes");
    const overrideError = document.getElementById("qrOverrideError");
    const confirmOverrideBtn = document.getElementById("confirmQrOverrideBtn");
    const checkoutPanel = document.getElementById("checkoutPanel");
    const checkoutStay = document.getElementById("checkoutStay");
    const checkoutWarnings = document.getElementById("checkoutWarnings");
    const confirmCheckoutBtn = document.getElementById("confirmCheckoutBtn");

    let scanner = null;
    // The pass waiting for the guard to confirm its check-out (second scan).
    let pendingCheckout = null;
    let scannerRunning = false;
    let scannerPaused = false;
    let scanLocked = false;
    let nextScanTimer = 0;
    let lastSentToken = "";
    let lastSentAt = 0;
    let pendingOverride = null;
    let photoMode = false;
    let photoScanSequence = 0;

    function canUseLiveCamera() {
        return window.isSecureContext || ["localhost", "127.0.0.1", "::1"].includes(window.location.hostname);
    }

    function setImageScanDisabled(disabled) {
        scanQrPhotoBtn.disabled = disabled;
        chooseQrImageBtn.disabled = disabled;
    }

    function normalizeToken(raw) {
        if (!raw || typeof raw !== "string") {
            return "";
        }

        const value = raw.trim();
        const tokenMatch = value.match(/[?&]token=([a-f0-9]{64})(?:&|$)/i);
        if (tokenMatch) {
            return tokenMatch[1].toLowerCase();
        }

        const hexOnly = value.replace(/[^a-f0-9]/gi, "");
        return hexOnly.length === 64 ? hexOnly.toLowerCase() : "";
    }

    function setCameraStatus(state, statusText, message, placeholderTitle, placeholderText) {
        cameraState.className = "security-camera-state is-" + state;
        cameraStateText.textContent = statusText;
        cameraMessage.textContent = message;
        cameraPlaceholderTitle.textContent = placeholderTitle || statusText;
        cameraPlaceholderText.textContent = placeholderText || message;

        const isScanning = state === "active";
        cameraPlaceholder.hidden = isScanning;
        scannerGuide.hidden = !isScanning;
        retryCameraBtn.hidden = state !== "error";
    }

    function showPhotoReady() {
        photoMode = true;
        setCameraStatus(
            "ready",
            "Phone camera",
            "Tap the button below to photograph the visitor QR code.",
            "Ready to scan",
            "The QR photo is decoded on this device, then securely checked by the server."
        );
        retryCameraBtn.hidden = !canUseLiveCamera();
        setImageScanDisabled(false);
    }

    function hideCheckout() {
        pendingCheckout = null;
        checkoutPanel.hidden = true;
        checkoutStay.hidden = true;
        checkoutWarnings.hidden = true;
        checkoutWarnings.replaceChildren();
    }

    /** "45 seconds", "12 minutes", "1 hour 5 min". */
    function formatDuration(seconds) {
        const value = Math.max(0, Math.round(Number(seconds) || 0));
        if (value < 60) {
            return value + " second" + (value === 1 ? "" : "s");
        }
        const minutes = Math.floor(value / 60);
        if (minutes < 60) {
            return minutes + " minute" + (minutes === 1 ? "" : "s");
        }
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;
        return hours + " hour" + (hours === 1 ? "" : "s") + (rest ? " " + rest + " min" : "");
    }

    function showReadyResult() {
        hideCheckout();
        scanResult.className = "security-scan-result is-ready";
        scanResultLabel.textContent = "Scanner ready";
        scanResultTitle.textContent = "Waiting for a visitor pass";
        scanResultText.textContent = "A successful scan will show the visitor details here.";
        scanResultDetails.hidden = true;
        scanScheduleRow.hidden = true;
        scanWindowRow.hidden = true;
        scanTimeInRow.hidden = true;
        openOverrideBtn.hidden = true;
        scanNextBtn.hidden = true;
        pendingOverride = null;
    }

    function showProcessingResult() {
        hideCheckout();
        scanResult.className = "security-scan-result is-processing";
        scanResultLabel.textContent = "Checking pass";
        scanResultTitle.textContent = "Please wait...";
        scanResultText.textContent = "Verifying the visitor appointment and check-in status.";
        scanResultDetails.hidden = true;
        openOverrideBtn.hidden = true;
        scanNextBtn.hidden = true;
    }

    function formatSchedule(value) {
        if (!value) {
            return "Schedule unavailable";
        }
        const date = new Date(String(value).replace(" ", "T"));
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }
        return new Intl.DateTimeFormat("en-PH", {
            month: "short",
            day: "numeric",
            year: "numeric",
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
    }

    function formatPassWindow(data) {
        if (!data || (!data.qr_valid_from && !data.qr_valid_until)) {
            return "";
        }
        if (data.qr_valid_from && data.qr_valid_until) {
            return formatSchedule(data.qr_valid_from) + " to " + formatSchedule(data.qr_valid_until);
        }
        return formatSchedule(data.qr_valid_from || data.qr_valid_until);
    }

    function updateResultDetails(data) {
        const hasAppointment = Boolean(data && data.appointment_id);
        scanResultDetails.hidden = !hasAppointment;
        if (!hasAppointment) {
            scanScheduleRow.hidden = true;
            scanWindowRow.hidden = true;
            return;
        }

        scanVisitorName.textContent = data.visitor_full_name || "Visitor";
        scanDestination.textContent = data.office_label || "Not specified";

        const hasSchedule = Boolean(data.scheduled_start_at);
        scanScheduleRow.hidden = !hasSchedule;
        scanSchedule.textContent = hasSchedule
            ? formatSchedule(data.scheduled_start_at) + (data.scheduled_end_at ? " to " + formatSchedule(data.scheduled_end_at) : "")
            : "-";

        const passWindow = formatPassWindow(data);
        scanWindowRow.hidden = !passWindow;
        scanWindow.textContent = passWindow || "-";

        const hasTimeIn = Boolean(data.checked_in_at);
        scanTimeInRow.hidden = !hasTimeIn;
        scanTimeIn.textContent = hasTimeIn ? formatSchedule(data.checked_in_at) : "-";
    }

    function errorPresentation(message, data) {
        const normalizedMessage = String(message || "").toLowerCase();
        if (data && data.action === "already_checked_out") {
            return { label: "Already checked out", title: "This visit has already ended" };
        }
        if (data && data.window_state === "too_early") {
            return {
                label: "Pass not active yet",
                title: "Check-in opens 30 minutes before the visit",
            };
        }
        if (data && (data.window_state === "closed" || data.status === "window_closed" || data.status === "completed")) {
            return { label: "Pass expired", title: "This appointment is already finished" };
        }
        if (data && data.status === "pending_approval") {
            return { label: "Awaiting approval", title: "The office has not approved this visit yet" };
        }
        if (data && data.status === "reschedule_proposed") {
            return { label: "Visitor response needed", title: "The proposed schedule must be accepted first" };
        }
        if (data && ["rejected", "cancelled", "unanswered"].includes(data.status)) {
            return { label: "Pass inactive", title: "This appointment cannot be checked in" };
        }
        if (normalizedMessage.includes("no readable qr") || normalizedMessage.includes("invalid or incomplete")) {
            return { label: "QR not read", title: "The visitor pass could not be read" };
        }
        if (normalizedMessage.includes("no appointment found") || normalizedMessage.includes("invalid qr")) {
            return { label: "Pass not recognized", title: "This is not a valid visitor pass" };
        }
        if (normalizedMessage.includes("server") || normalizedMessage.includes("service") || normalizedMessage.includes("database")) {
            return { label: "Service unavailable", title: "The check-in service is not responding" };
        }
        return { label: "Pass not accepted", title: "Check-in unsuccessful" };
    }

    function revealScanResult() {
        window.requestAnimationFrame(function () {
            scanResult.scrollIntoView({ behavior: "smooth", block: "center" });
        });
    }

    function showErrorResult(message, data) {
        hideCheckout();
        const presentation = errorPresentation(message, data);
        scanResult.className = "security-scan-result is-error";
        scanResultLabel.textContent = presentation.label;
        scanResultTitle.textContent = presentation.title;
        scanResultText.textContent = message || "The visitor pass could not be verified.";
        const canOverride = Boolean(data && data.can_override && data.appointment_id);
        pendingOverride = canOverride ? { token: lastSentToken, appointment: data } : null;
        updateResultDetails(data);
        openOverrideBtn.hidden = !canOverride;
        scanNextBtn.textContent = "Try another pass";
        scanNextBtn.hidden = false;
        revealScanResult();
    }

    function showSuccessResult(data) {
        hideCheckout();
        const alreadyCheckedIn = Boolean(data.already_checked_in);
        scanResult.className = "security-scan-result is-success";
        scanResultLabel.textContent = alreadyCheckedIn ? "Already checked in" : "Check-in complete";
        scanResultTitle.textContent = alreadyCheckedIn ? "This visitor was just checked in" : "Visitor checked in successfully";
        scanResultText.textContent = alreadyCheckedIn
            ? (data.message || "No duplicate check-in was created.")
            : "Status: Active. Time in was recorded and the visitor app can now start campus tracking. Scan this pass again when they leave.";
        updateResultDetails(data);
        openOverrideBtn.hidden = true;
        scanNextBtn.textContent = "Scan next visitor";
        scanNextBtn.hidden = false;
        pendingOverride = null;
        revealScanResult();
    }

    /** Second scan of a pass: the guard confirms the check-out with one tap. */
    function showCheckoutResult(data) {
        hideCheckout();
        const info = data.checkout || {};
        const name = info.visitor_full_name || data.visitor_full_name || "this visitor";
        scanResult.className = "security-scan-result is-checkout";
        scanResultLabel.textContent = "Ready to check out";
        scanResultTitle.textContent = "Check out " + name + "?";
        scanResultText.textContent = "On campus for " + formatDuration(info.seconds_on_campus)
            + (info.checked_in_by ? ". Checked in by " + info.checked_in_by : "") + ".";
        updateResultDetails(Object.assign({}, data, {
            appointment_id: info.appointment_id || data.appointment_id,
            office_label: info.office_label || data.office_label,
            qr_valid_from: null,
            qr_valid_until: null,
        }));
        if (Number(info.minutes_past_slot) > 0) {
            checkoutStay.textContent = "Stayed " + formatDuration(info.minutes_past_slot * 60) + " past their booked slot.";
            checkoutStay.hidden = false;
        }
        (info.warnings || []).forEach(function (warning) {
            const item = document.createElement("li");
            item.textContent = warning;
            checkoutWarnings.appendChild(item);
        });
        checkoutWarnings.hidden = !(info.warnings && info.warnings.length);
        pendingCheckout = { token: lastSentToken, name: name };
        checkoutPanel.hidden = false;
        openOverrideBtn.hidden = true;
        scanNextBtn.textContent = "Not now";
        scanNextBtn.hidden = false;
        revealScanResult();
        window.setTimeout(function () { confirmCheckoutBtn.focus({ preventScroll: true }); }, 60);
    }

    function showCheckedOutResult(data) {
        hideCheckout();
        scanResult.className = "security-scan-result is-success";
        scanResultLabel.textContent = "Checked out";
        scanResultTitle.textContent = (data.visitor_full_name || "Visitor") + " checked out";
        scanResultText.textContent = data.message || "Time out was recorded and location tracking has stopped.";
        updateResultDetails({
            appointment_id: data.appointment_id,
            visitor_full_name: data.visitor_full_name,
            office_label: data.office_label,
            checked_in_at: data.checked_in_at,
        });
        openOverrideBtn.hidden = true;
        scanNextBtn.textContent = "Scan next visitor";
        scanNextBtn.hidden = false;
        revealScanResult();
    }

    function confirmCheckout() {
        if (!pendingCheckout) {
            return;
        }
        const checkout = pendingCheckout;
        confirmCheckoutBtn.disabled = true;
        confirmCheckoutBtn.querySelector("span").textContent = "Checking out…";
        fetch("scan_checkout.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ token: checkout.token }),
        })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error("The check-out service returned an invalid response. Please try again.");
                });
            })
            .then(function (data) {
                if (data && data.success) {
                    showCheckedOutResult(data);
                } else {
                    showErrorResult((data && data.message) || "Could not check out this visitor.", data);
                }
            })
            .catch(function (error) {
                showErrorResult(error.message || "Could not reach the server. Please try again.", null);
            })
            .finally(function () {
                confirmCheckoutBtn.disabled = false;
                confirmCheckoutBtn.querySelector("span").textContent = "Check out visitor";
            });
    }

    function pauseScanner() {
        if (!scannerRunning || !scanner || typeof scanner.pause !== "function") {
            return;
        }

        try {
            scanner.pause(true);
            scannerPaused = true;
        } catch (error) {}
    }

    function prepareNextScan() {
        window.clearTimeout(nextScanTimer);
        photoScanSequence += 1;
        scanLocked = false;
        submitTokenBtn.disabled = false;
        setImageScanDisabled(false);
        tokenInput.value = "";
        showReadyResult();

        if (photoMode) {
            showPhotoReady();
            return;
        }

        if (scannerPaused && scanner && typeof scanner.resume === "function") {
            try {
                scanner.resume();
                scannerPaused = false;
            } catch (error) {
                loadCameras();
            }
        }
    }

    function checkIn(rawToken) {
        if (scanLocked) {
            return;
        }

        const token = normalizeToken(rawToken);
        if (!token) {
            setImageScanDisabled(false);
            showErrorResult("That visitor pass code is invalid or incomplete. Ask the visitor to reopen their QR pass and try again.", null);
            manualEntry.open = true;
            tokenInput.focus();
            return;
        }

        const now = Date.now();
        if (token === lastSentToken && now - lastSentAt < 4000) {
            return;
        }

        lastSentToken = token;
        lastSentAt = now;
        scanLocked = true;
        submitTokenBtn.disabled = true;
        setImageScanDisabled(true);
        showProcessingResult();

        fetch("scan_appointment.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ token: token }),
        })
            .then(function (response) {
                return response.text().then(function (body) {
                    try {
                        return JSON.parse(body);
                    } catch (error) {
                        throw new Error("The check-in service returned an invalid response. Confirm that Apache and MySQL are running, then try again.");
                    }
                });
            })
            .then(function (data) {
                if (!data || !data.success) {
                    submitTokenBtn.disabled = false;
                    setImageScanDisabled(false);
                    if (data && data.can_override) {
                        pauseScanner();
                        scanLocked = true;
                    } else {
                        scanLocked = false;
                    }
                    showErrorResult((data && data.message) || "The visitor pass could not be checked in.", data);
                    return;
                }

                pauseScanner();
                if (data.action === "checkout_ready") {
                    showCheckoutResult(data);
                    return;
                }
                showSuccessResult(data);
            })
            .catch(function (error) {
                scanLocked = false;
                submitTokenBtn.disabled = false;
                setImageScanDisabled(false);
                showErrorResult(error.message || "Could not reach the check-in server. Confirm that Apache and MySQL are running, then try again.", null);
            });
    }

    function openOverrideDialog() {
        if (!pendingOverride) {
            return;
        }
        const data = pendingOverride.appointment;
        document.getElementById("overrideVisitorName").textContent = data.visitor_full_name || "Visitor";
        document.getElementById("overrideDestination").textContent = data.office_label || "Not specified";
        document.getElementById("overrideSchedule").textContent = formatSchedule(data.scheduled_start_at) + " – " + formatSchedule(data.scheduled_end_at);
        overrideReason.value = "";
        overrideMinutes.value = "30";
        overrideError.textContent = "";
        overrideError.hidden = true;
        overrideDialog.showModal();
        window.setTimeout(function () { overrideReason.focus(); }, 50);
    }

    function closeOverrideDialog() {
        overrideDialog.close();
        overrideError.textContent = "";
        overrideError.hidden = true;
    }

    function cameraPriority(camera) {
        const label = camera && camera.label ? camera.label.toLowerCase() : "";
        if (label.includes("back") || label.includes("rear") || label.includes("environment")) {
            return 0;
        }
        if (label.includes("front") || label.includes("user") || label.includes("facetime")) {
            return 2;
        }
        return 1;
    }

    function stopScanner() {
        if (!scanner || !scannerRunning) {
            return Promise.resolve();
        }

        if (scannerPaused && typeof scanner.resume === "function") {
            try {
                scanner.resume();
            } catch (error) {}
            scannerPaused = false;
        }

        return scanner.stop()
            .catch(function () {})
            .then(function () {
                scannerRunning = false;
            });
    }

    function startScanner(cameraId) {
        photoMode = false;
        cameraSelect.disabled = true;
        retryCameraBtn.disabled = true;
        setCameraStatus(
            "loading",
            "Starting",
            "Opening the selected camera...",
            "Opening camera",
            "This usually takes only a moment."
        );

        if (!scanner) {
            scanner = new Html5Qrcode(readerId);
        }

        return stopScanner()
            .then(function () {
                return scanner.start(
                    cameraId,
                    { fps: 10, qrbox: { width: 250, height: 250 } },
                    function (decodedText) {
                        checkIn(decodedText);
                    },
                    function () {}
                );
            })
            .then(function () {
                scannerRunning = true;
                cameraSelect.disabled = false;
                retryCameraBtn.disabled = false;
                setCameraStatus(
                    "active",
                    "Scanning",
                    "Camera is active and ready to scan.",
                    "Camera active",
                    "Place the visitor QR code inside the frame."
                );
            })
            .catch(function () {
                scannerRunning = false;
                cameraSelect.disabled = false;
                retryCameraBtn.disabled = false;
                manualEntry.open = true;
                setCameraStatus(
                    "error",
                    "Camera blocked",
                    "Camera permission was denied or the camera is unavailable.",
                    "Camera access needed",
                    "Allow camera access in your browser, then try again."
                );
            });
    }

    function loadCameras() {
        if (typeof Html5Qrcode === "undefined") {
            setImageScanDisabled(true);
            manualEntry.open = true;
            setCameraStatus(
                "error",
                "Scanner unavailable",
                "The camera scanner could not be loaded.",
                "Scanner unavailable",
                "Use manual entry or check the internet connection."
            );
            return;
        }

        if (!canUseLiveCamera()) {
            cameraSelectField.hidden = true;
            manualEntry.open = false;
            showPhotoReady();
            return;
        }

        retryCameraBtn.disabled = true;
        setCameraStatus(
            "loading",
            "Starting",
            "Requesting camera access...",
            "Connecting to camera",
            "Allow camera access when your browser asks."
        );

        Html5Qrcode.getCameras()
            .then(function (devices) {
                if (!devices || devices.length === 0) {
                    throw new Error("No camera found");
                }

                const cameras = devices.slice().sort(function (a, b) {
                    return cameraPriority(a) - cameraPriority(b);
                });

                cameraSelect.innerHTML = "";
                cameras.forEach(function (camera, index) {
                    const option = document.createElement("option");
                    option.value = camera.id;
                    option.textContent = camera.label || "Camera " + (index + 1);
                    cameraSelect.appendChild(option);
                });

                cameraSelectField.hidden = cameras.length < 2;
                cameraSelect.value = cameras[0].id;
                return startScanner(cameras[0].id);
            })
            .catch(function () {
                retryCameraBtn.disabled = false;
                manualEntry.open = true;
                setCameraStatus(
                    "error",
                    "Camera blocked",
                    "Camera permission was denied or no camera is available.",
                    "Camera access needed",
                    "Allow camera access in your browser, then try again."
                );
            });
    }

    function decodedTextFromResult(result) {
        if (typeof result === "string") {
            return result;
        }
        return result && result.decodedText ? result.decodedText : "";
    }

    async function decodeWithNativeDetector(file) {
        if (!("BarcodeDetector" in window) || !("createImageBitmap" in window)) {
            return "";
        }

        let bitmap = null;
        try {
            const detector = new window.BarcodeDetector({ formats: ["qr_code"] });
            bitmap = await window.createImageBitmap(file);
            const results = await detector.detect(bitmap);
            return results && results[0] && results[0].rawValue ? results[0].rawValue : "";
        } catch (error) {
            return "";
        } finally {
            if (bitmap && typeof bitmap.close === "function") {
                bitmap.close();
            }
        }
    }

    async function decodeWithHtml5Qrcode(file) {
        if (typeof Html5Qrcode === "undefined") {
            return "";
        }
        if (!scanner) {
            scanner = new Html5Qrcode(readerId);
        }

        try {
            if (typeof scanner.scanFileV2 === "function") {
                return decodedTextFromResult(await scanner.scanFileV2(file, false));
            }
            return decodedTextFromResult(await scanner.scanFile(file, false));
        } catch (error) {
            return "";
        }
    }

    function loadPhoto(file) {
        return new Promise(function (resolve, reject) {
            const objectUrl = URL.createObjectURL(file);
            const image = new Image();
            image.onload = function () {
                URL.revokeObjectURL(objectUrl);
                resolve(image);
            };
            image.onerror = function () {
                URL.revokeObjectURL(objectUrl);
                reject(new Error("Photo could not be opened"));
            };
            image.src = objectUrl;
        });
    }

    function canvasFile(canvas, name) {
        return new Promise(function (resolve) {
            canvas.toBlob(function (blob) {
                resolve(blob ? new File([blob], name, { type: "image/png" }) : null);
            }, "image/png");
        });
    }

    async function createQrPhotoVariants(file) {
        try {
            const image = await loadPhoto(file);
            const maxSide = 1800;
            const scale = Math.min(1, maxSide / Math.max(image.naturalWidth, image.naturalHeight));
            const width = Math.max(1, Math.round(image.naturalWidth * scale));
            const height = Math.max(1, Math.round(image.naturalHeight * scale));
            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext("2d", { willReadFrequently: true });
            if (!context) {
                return [];
            }
            context.drawImage(image, 0, 0, width, height);

            const resized = await canvasFile(canvas, "visitor-pass-resized.png");
            const pixels = context.getImageData(0, 0, width, height);
            for (let index = 0; index < pixels.data.length; index += 4) {
                const luminance = (pixels.data[index] * 0.299) + (pixels.data[index + 1] * 0.587) + (pixels.data[index + 2] * 0.114);
                const value = luminance > 155 ? 255 : 0;
                pixels.data[index] = value;
                pixels.data[index + 1] = value;
                pixels.data[index + 2] = value;
            }
            context.putImageData(pixels, 0, 0);
            const highContrast = await canvasFile(canvas, "visitor-pass-contrast.png");
            return [resized, highContrast].filter(Boolean);
        } catch (error) {
            return [];
        }
    }

    async function decodeQrPhoto(file) {
        let decodedText = await decodeWithNativeDetector(file);
        if (!decodedText) {
            decodedText = await decodeWithHtml5Qrcode(file);
        }
        if (decodedText) {
            return decodedText;
        }

        const variants = await createQrPhotoVariants(file);
        for (const variant of variants) {
            decodedText = await decodeWithNativeDetector(variant);
            if (!decodedText) {
                decodedText = await decodeWithHtml5Qrcode(variant);
            }
            if (decodedText) {
                return decodedText;
            }
        }
        return "";
    }

    function scanQrPhoto(file) {
        if (!file || scanLocked) {
            return;
        }

        if (!String(file.type || "").startsWith("image/")) {
            showErrorResult("Choose a photo or screenshot containing the visitor QR code.", null);
            return;
        }
        if (file.size > 20 * 1024 * 1024) {
            showErrorResult("That image is too large. Choose a QR image smaller than 20 MB.", null);
            return;
        }

        photoMode = true;
        const currentScan = ++photoScanSequence;
        setImageScanDisabled(true);
        setCameraStatus(
            "loading",
            "Reading QR",
            "Reading the QR code from the photo...",
            "Reading QR code",
            "Keep this page open for a moment."
        );

        stopScanner()
            .then(function () {
                return decodeQrPhoto(file);
            })
            .then(function (decodedText) {
                if (currentScan !== photoScanSequence) {
                    return;
                }
                if (!decodedText) {
                    showPhotoReady();
                    showErrorResult("No readable QR code was found, so no check-in request was sent. Keep the whole QR visible, avoid glare, and tap the camera checkmark or Use photo before returning.", null);
                    return;
                }
                showPhotoReady();
                checkIn(decodedText);
            }, function () {
                if (currentScan !== photoScanSequence || scanLocked) {
                    return;
                }
                showPhotoReady();
                showErrorResult("No readable QR code was found, so no check-in request was sent. Keep the whole QR visible, avoid glare, and tap the camera checkmark or Use photo before returning.", null);
            })
            .catch(function () {
                if (currentScan !== photoScanSequence || scanLocked) {
                    return;
                }
                showPhotoReady();
                showErrorResult("The selected photo could not be processed. Try a screenshot of the QR code or enter the visitor pass code manually.", null);
            });
    }

    manualForm.addEventListener("submit", function (event) {
        event.preventDefault();
        checkIn(tokenInput.value);
    });

    retryCameraBtn.addEventListener("click", loadCameras);
    scanQrPhotoBtn.addEventListener("click", function () {
        qrPhotoInput.click();
    });
    qrPhotoInput.addEventListener("change", function () {
        const file = qrPhotoInput.files && qrPhotoInput.files[0];
        qrPhotoInput.value = "";
        scanQrPhoto(file);
    });
    chooseQrImageBtn.addEventListener("click", function () {
        qrImageInput.click();
    });
    qrImageInput.addEventListener("change", function () {
        const file = qrImageInput.files && qrImageInput.files[0];
        qrImageInput.value = "";
        scanQrPhoto(file);
    });
    cameraSelect.addEventListener("change", function () {
        if (cameraSelect.value) {
            startScanner(cameraSelect.value);
        }
    });
    scanNextBtn.addEventListener("click", prepareNextScan);
    confirmCheckoutBtn.addEventListener("click", confirmCheckout);
    openOverrideBtn.addEventListener("click", openOverrideDialog);
    document.getElementById("closeQrOverrideBtn").addEventListener("click", closeOverrideDialog);
    document.getElementById("cancelQrOverrideBtn").addEventListener("click", closeOverrideDialog);
    overrideDialog.addEventListener("click", function (event) {
        if (event.target === overrideDialog) {
            closeOverrideDialog();
        }
    });
    overrideForm.addEventListener("submit", function (event) {
        event.preventDefault();
        if (!pendingOverride) {
            closeOverrideDialog();
            return;
        }
        const reason = overrideReason.value.trim();
        if (reason.length < 5) {
            overrideError.textContent = "Enter a clear reason with at least 5 characters.";
            overrideError.hidden = false;
            return;
        }
        confirmOverrideBtn.disabled = true;
        confirmOverrideBtn.textContent = "Authorizing…";
        overrideError.hidden = true;
        fetch("create_qr_override.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                appointment_id: Number(pendingOverride.appointment.appointment_id),
                reason: reason,
                valid_minutes: Number(overrideMinutes.value || 30),
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || "Could not authorize the override.");
                }
                const token = pendingOverride.token;
                closeOverrideDialog();
                scanLocked = false;
                lastSentAt = 0;
                checkIn(token);
            })
            .catch(function (error) {
                overrideError.textContent = error.message || "Could not authorize the override.";
                overrideError.hidden = false;
            })
            .finally(function () {
                confirmOverrideBtn.disabled = false;
                confirmOverrideBtn.textContent = "Authorize and check in";
            });
    });

    window.addEventListener("beforeunload", function () {
        window.clearTimeout(nextScanTimer);
        if (scanner && scannerRunning && typeof scanner.stop === "function") {
            scanner.stop().catch(function () {});
        }
    });

    showReadyResult();
    loadCameras();
})();
