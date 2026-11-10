/*
 * Sign-in page: username/password, then any extra steps the server asks for
 * (two-step code, new password, two-step setup, backup codes). The logo
 * animation plays only once the sign-in has succeeded (see "The sign-in animation").
 * The server decides everything; this file only presents each step.
 */
(function () {
    "use strict";

    const ROLE_HOME = {
        admin: "admin.html",
        security: "dashboard.html",
        offices: "offices.html",
        visitor: "index.html",
    };
    const ROLE_PLACE = {
        admin: "the Admin dashboard",
        security: "the Security dashboard",
        offices: "the Office dashboard",
        visitor: "your visitor page",
    };
    // The animation's timeline in ms. The first beat is the password check, which is done
    // before the animation opens, so it opens at that beat.
    const CHECK_BEATS = [560, 1000, 1440, 1880];
    const LEAVE_AT = 3600;
    const OPEN_MS = 420;
    const LEAVE_MS = 400;
    const CLOSE_MS = 340;
    // Each part waits for its moment on the timeline, but never less than these.
    const MIN_CHECK_MS = 240;
    const MIN_WELCOME_MS = 1420;

    const UI = window.PhoneTrackerAuthUI;
    const Auth = window.PhoneTrackerAuth;
    const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    const $ = function (id) { return document.getElementById(id); };
    const panels = {
        signin: $("signInPanel"),
        twoFactor: $("twoFactorPanel"),
        password: $("passwordPanel"),
        setup: $("setupPanel"),
        backup: $("backupPanel"),
    };
    const loginForm = $("loginForm");
    const usernameInput = $("loginEmail");
    const passwordInput = $("loginPassword");
    const signInBtn = $("signInBtn");
    const loginMessage = $("loginMessage");
    const attemptsHint = $("attemptsHint");

    let busy = false;

    function wait(ms) {
        return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
    }

    function showMessage(element, message, state) {
        element.textContent = message || "";
        element.hidden = !message;
        element.dataset.state = state || "error";
    }

    function showNotice(message, tone) {
        const notice = $("authNotice");
        $("authNoticeText").textContent = message || "";
        notice.dataset.tone = tone || "info";
        notice.hidden = !message;
    }

    function api(url, body, method) {
        const options = { method: method || "POST", credentials: "same-origin", cache: "no-store" };
        if (body) {
            options.headers = { "Content-Type": "application/json" };
            options.body = JSON.stringify(body);
        }
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (data) {
                return { status: response.status, data: data || {} };
            });
        }, function () {
            return { status: 0, data: {} };
        });
    }

    function submitForm(form) {
        if (typeof form.requestSubmit === "function") {
            form.requestSubmit();
        } else {
            form.dispatchEvent(new Event("submit", { cancelable: true }));
        }
    }

    function showPanel(name) {
        progress.close();
        Object.keys(panels).forEach(function (key) {
            panels[key].hidden = key !== name;
        });
        const panel = panels[name];
        panel.classList.remove("is-entering");
        void panel.offsetWidth;
        panel.classList.add("is-entering");
        const heading = panel.querySelector("[tabindex='-1']");
        if (heading && name !== "signin") {
            heading.focus({ preventScroll: true });
        }
    }

    /* ---------- The sign-in animation ----------
       It plays only once the sign-in has succeeded: straight after the password, or after the
       last further step, such as the two-step code. A refused sign-in and each further step
       show on the form without it.
       The seal in the header comes to the middle of the screen wound back: its gear three teeth
       behind, as the first of the four checks (the password) has passed, while the same gear
       grows behind it to fill the window. Each further check turns the gear one tooth and opens
       the centre further. The fourth brings the gear upright, the torch lights, and everything
       returns to the header. */

    const progress = (function () {
        const CHECKS = ["Checking your password", "Checking account security", "Securing your session"];
        const TEETH = 16;
        const WOUND_BACK_DEGREES = -90;
        const FLIGHT_FRAMES = 30;

        const splash = $("splash");
        const seal = $("splashSeal");
        const field = $("splashField");
        const title = $("splashTitle");
        const note = $("splashNote");
        const live = $("splashLive");
        const mark = document.querySelector(".auth-brand-mark");
        const outside = [document.querySelector(".auth-header"), document.querySelector(".auth-main")];

        let isOpen = false;
        let startedAt = 0;
        let clicks = 4;
        let flights = [];
        let hideTimer = 0;
        let wordsFrom = 0;

        // The seal's own teeth, measured from the logo, a little inside it so they stay hidden
        // behind the seal when both are back in the header. Percent of the seal's box.
        const ROOT = 44.1;
        const TIP = 48.8;
        const outline = [];
        for (let tooth = 0; tooth < TEETH; tooth += 1) {
            [[-8.5, ROOT], [-4.2, TIP], [4.2, TIP], [8.5, ROOT]].forEach(function (corner) {
                const angle = (tooth * 360 / TEETH + corner[0]) * Math.PI / 180;
                outline.push((50 + corner[1] * Math.cos(angle)).toFixed(2) + "% " + (50 + corner[1] * Math.sin(angle)).toFixed(2) + "%");
            });
        }
        field.style.clipPath = "polygon(" + outline.join(",") + ")";

        function easeOut(t) {
            return 1 - Math.pow(1 - t, 3);
        }

        function easeInOut(t) {
            return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
        }

        /* Moves the seal between the middle of the screen and the header, with the field gear
           in step. 0 is the middle of the screen; 1 is the header. Its curve meets the header
           from below, so it doesn't cross the name beside the logo. */
        function fly(from, to, duration, ease) {
            flights.forEach(function (flight) { flight.cancel(); });
            const box = seal.getBoundingClientRect();
            const home = mark.getBoundingClientRect();
            const sealX = box.left + box.width / 2;
            const sealY = box.top + box.height / 2;
            const x = home.left + home.width / 2 - sealX;
            const y = home.top + home.height / 2 - sealY;
            const shrink = home.height / box.height;
            const farthest = Math.hypot(Math.max(sealX, window.innerWidth - sealX), Math.max(sealY, window.innerHeight - sealY));
            const cover = 1.04 * farthest / (box.width * ROOT / 100);
            const sealFrames = [];
            const fieldFrames = [];
            for (let frame = 0; frame <= FLIGHT_FRAMES; frame += 1) {
                const p = from + (to - from) * ease(frame / FLIGHT_FRAMES);
                sealFrames.push({ transform: "translate(" + (x * Math.pow(p, 0.8)) + "px," + (y * Math.pow(p, 1.3)) + "px) scale(" + Math.pow(shrink, p) + ")" });
                fieldFrames.push({ transform: "rotate(" + (WOUND_BACK_DEGREES * (1 - p)) + "deg) scale(" + Math.pow(cover, 1 - p) + ")" });
            }
            const timing = { duration: duration, fill: "both" };
            flights = [seal.animate(sealFrames, timing), field.animate(fieldFrames, timing)];
        }

        function setClicks(count) {
            clicks = count;
            seal.dataset.clicks = String(count);
        }

        function say(text) {
            title.textContent = text;
            live.textContent = text;
            if (!reduceMotion) {
                title.animate(
                    [{ opacity: 0, transform: "translateY(6px)" }, { opacity: 1, transform: "none" }],
                    { duration: 220, delay: Math.max(0, wordsFrom - performance.now()), easing: "cubic-bezier(0.2, 0.7, 0.2, 1)", fill: "backwards" }
                );
            }
        }

        function fadeWords() {
            if (!reduceMotion) {
                [title, note].forEach(function (element) {
                    element.animate({ opacity: [1, 0] }, { duration: 140, fill: "forwards" });
                });
            }
        }

        function clearWords() {
            [title, note].forEach(function (element) {
                element.getAnimations().forEach(function (animation) { animation.cancel(); });
                element.textContent = "";
            });
        }

        // Waits for a moment on the timeline; if that has passed, for the shortest pause allowed.
        function until(moment, shortest) {
            return wait(Math.max(startedAt + moment - performance.now(), shortest));
        }

        /* Back to the header, whole again. The caller decides what is under the splash. */
        function goHome(duration, ease) {
            if (reduceMotion) {
                splash.animate({ opacity: [1, 0] }, { duration: 160, fill: "forwards" });
            } else {
                splash.classList.remove("is-covered");
                fly(0, 1, duration, ease);
            }
        }

        return {
            /* Starts the animation with this many checks already passed. */
            open: function (passed) {
                window.clearTimeout(hideTimer);
                isOpen = true;
                startedAt = performance.now() - (passed ? CHECK_BEATS[passed - 1] : 0);
                wordsFrom = performance.now() + OPEN_MS / 2;
                clearWords();
                splash.getAnimations().forEach(function (animation) { animation.cancel(); });
                splash.classList.remove("is-covered");
                splash.hidden = false;
                document.body.classList.add("auth-splash-open");
                outside.forEach(function (element) { element.inert = true; });

                // It leaves the header looking like the logo there, then winds back on the way.
                seal.classList.add("is-jumping");
                seal.classList.remove("is-lighting");
                seal.dataset.lit = "true";
                setClicks(4);
                void seal.offsetWidth;
                seal.classList.remove("is-jumping");
                seal.classList.add("is-winding");
                setClicks(passed || 0);

                if (reduceMotion) {
                    splash.classList.add("is-covered");
                    splash.animate({ opacity: [0, 1] }, { duration: 160 });
                    seal.dataset.lit = "false";
                } else {
                    document.body.classList.add("auth-seal-away");
                    fly(1, 0, OPEN_MS, easeOut);
                    window.setTimeout(function () {
                        if (isOpen) {
                            splash.classList.add("is-covered");
                            seal.classList.remove("is-winding");
                            seal.dataset.lit = "false";
                        }
                    }, OPEN_MS);
                }
                say(CHECKS[passed || 0]);
                title.focus({ preventScroll: true });
            },
            /* Announces a check; resolves when its beat comes. */
            run: function (index, text) {
                const words = text || CHECKS[index];
                if (title.textContent !== words) {
                    say(words);
                }
                return until(CHECK_BEATS[index], MIN_CHECK_MS);
            },
            /* A check passed: the gear turns one tooth. */
            pass: function () {
                setClicks(clicks + 1);
            },
            note: function (text) {
                note.textContent = text;
                if (!reduceMotion) {
                    note.animate({ opacity: [0, 1] }, { duration: 300 });
                }
            },
            /* The torch lights. */
            success: function (heading) {
                say(heading);
                seal.dataset.lit = "true";
                seal.classList.add("is-lighting");
            },
            /* Holds the welcome, then returns to the header. Resolves when it has landed. */
            leave: function () {
                return until(LEAVE_AT, MIN_WELCOME_MS).then(function () {
                    fadeWords();
                    goHome(LEAVE_MS, easeInOut);
                    return wait(LEAVE_MS);
                });
            },
            /* Back to the form or a further step, which is already showing underneath. */
            close: function () {
                if (!isOpen) {
                    return;
                }
                isOpen = false;
                document.body.classList.remove("auth-splash-open");
                outside.forEach(function (element) { element.inert = false; });
                fadeWords();
                seal.dataset.lit = "true";
                seal.classList.add("is-winding");
                setClicks(4);
                goHome(CLOSE_MS, easeInOut);
                hideTimer = window.setTimeout(function () {
                    splash.hidden = true;
                    document.body.classList.remove("auth-seal-away");
                    seal.classList.remove("is-winding");
                    flights.forEach(function (flight) { flight.cancel(); });
                    clearWords();
                }, reduceMotion ? 160 : CLOSE_MS);
            },
        };
    })();

    /* ---------- Sign-in form ---------- */

    UI.bindPasswordToggles(document);

    const capsLockHint = $("capsLockHint");
    ["keydown", "keyup"].forEach(function (name) {
        passwordInput.addEventListener(name, function (event) {
            if (event.getModifierState) {
                capsLockHint.hidden = !event.getModifierState("CapsLock");
            }
        });
    });
    passwordInput.addEventListener("blur", function () {
        capsLockHint.hidden = true;
    });

    let lockState = null;
    function renderLock() {
        const left = lockState ? Math.ceil((lockState.until - Date.now()) / 1000) : 0;
        const appliesHere = lockState && (lockState.scope !== "account_device"
            || usernameInput.value.trim().toLowerCase() === lockState.username);
        if (lockState && left <= 0) {
            window.clearInterval(lockState.timer);
            lockState = null;
            showMessage(loginMessage, "You can try again now.", "success");
        }
        $("lockBanner").hidden = !lockState || !appliesHere;
        signInBtn.disabled = Boolean(lockState && appliesHere);
        if (lockState) {
            $("lockCountdown").textContent = UI.formatCountdown(left);
        }
    }

    function startLock(seconds, message, scope) {
        if (lockState) {
            window.clearInterval(lockState.timer);
        }
        lockState = {
            until: Date.now() + Math.max(1, Number(seconds) || 600) * 1000,
            scope: scope || "account_device",
            username: usernameInput.value.trim().toLowerCase(),
            timer: window.setInterval(renderLock, 1000),
        };
        $("lockTitle").textContent = scope === "ip" ? "Sign-in paused on this network" : "Sign-in paused";
        $("lockText").textContent = message || "Too many failed attempts.";
        attemptsHint.hidden = true;
        loginMessage.hidden = true;
        renderLock();
    }
    usernameInput.addEventListener("input", renderLock);

    function showAttempts(data) {
        const left = data.attempts_remaining;
        if (typeof left !== "number" || left <= 0 || left > 2) {
            attemptsHint.hidden = true;
            return;
        }
        attemptsHint.textContent = (left === 1 ? "1 attempt" : left + " attempts")
            + " left before sign-in is paused for " + UI.formatMinutes(data.next_lock_minutes || 10) + ".";
        attemptsHint.hidden = false;
    }

    function returnToSignIn() {
        showPanel("signin");
        resetStepPanels();
        passwordInput.value = "";
        window.setTimeout(function () {
            (usernameInput.value ? passwordInput : usernameInput).focus();
        }, 40);
    }

    loginForm.addEventListener("submit", async function (event) {
        event.preventDefault();
        if (busy || signInBtn.disabled) {
            return;
        }
        showMessage(loginMessage, "");
        attemptsHint.hidden = true;
        const username = usernameInput.value.trim();
        const password = passwordInput.value;
        if (!username || !password) {
            showMessage(loginMessage, "Enter your username or email and your password.");
            (username ? passwordInput : usernameInput).focus();
            return;
        }

        busy = true;
        showNotice("");
        // No animation yet: if the server asks for a further step, that step shows straight away.
        const result = await withButton(signInBtn, "Signing in…", function () {
            return api("login.php", { username: username, password: password });
        });
        await route(result, "signin");
        busy = false;
    });

    /* ---------- Routing the server's answer ---------- */

    async function route(result, origin) {
        const data = result.data || {};
        if (data.status === "authenticated") {
            return completeSignIn(data);
        }
        const steps = {
            two_factor_required: "twoFactor",
            password_change_required: "password",
            two_factor_setup_required: "setup",
        };
        if (steps[data.status]) {
            openStep(steps[data.status], data);
            return undefined;
        }
        return origin === "signin" ? signInFailed(result) : stepFailed(result);
    }

    function signInFailed(result) {
        const data = result.data || {};
        const code = data.code || (result.status === 0 ? "network" : "error");
        passwordInput.value = "";
        passwordInput.focus();
        if (code === "locked") {
            startLock(data.retry_after, data.message, data.lock_scope);
            return;
        }
        const message = code === "network"
            ? "Can't reach the server. Check that XAMPP is running and you're on the right network."
            : (data.message || "Sign-in failed. Please try again.");
        showMessage(loginMessage, message);
        if (code === "invalid_credentials") {
            showAttempts(data);
        }
    }

    function stepFailed(result, context) {
        const data = result.data || {};
        if (data.code === "pending_expired" || data.code === "locked") {
            returnToSignIn();
            if (data.code === "locked") {
                startLock(data.retry_after, data.message, data.lock_scope);
            } else {
                showNotice(data.message || "Your sign-in timed out. Please start again.", "warning");
            }
            return;
        }
        let message = data.message || (result.status === 0 ? "Can't reach the server. Please try again." : "Something went wrong. Please try again.");
        if (typeof data.attempts_remaining === "number" && data.attempts_remaining > 0 && data.attempts_remaining <= 2) {
            message += " " + (data.attempts_remaining === 1 ? "1 attempt" : data.attempts_remaining + " attempts")
                + " left before sign-in is paused for " + UI.formatMinutes(data.next_lock_minutes || 10) + ".";
        }
        if (context) {
            showMessage(context.message, message);
            if (context.otp) {
                context.otp.invalid();
                context.otp.focus();
            }
        }
    }

    async function completeSignIn(data) {
        Auth.setSessionFromLogin(data);
        if (Array.isArray(data.backup_codes) && data.backup_codes.length) {
            await showBackupCodes(data.backup_codes, data.username);
        }
        // The animation starts here, and only here: the sign-in is complete.
        progress.open(1);

        const checked = progress.run(1);
        const notes = [];
        if (data.previous_login && data.previous_login.at) {
            notes.push("Last sign-in " + UI.formatDateTime(data.previous_login.at) + (data.previous_login.ip ? " from " + UI.formatIp(data.previous_login.ip) : ""));
        }
        if (typeof data.backup_codes_remaining === "number") {
            notes.push(data.backup_codes_remaining + (data.backup_codes_remaining === 1 ? " backup code left" : " backup codes left"));
        }
        if (notes.length) {
            progress.note(notes.join(". ") + ".");
        }

        await checked;
        progress.pass();
        await progress.run(2);
        progress.pass();
        await progress.run(3, "Opening " + (ROLE_PLACE[data.role] || "your dashboard"));
        progress.pass();
        progress.success("Welcome back, " + (data.display_name || data.username));
        await progress.leave();
        window.location.replace(ROLE_HOME[data.role] || data.redirect || "index.html");
    }

    /* ---------- Step panels ---------- */

    const totp = UI.bindOtpInput($("totpCode"), document.querySelector("#totpGroup .auth-otp-slots"), function () {
        submitForm($("twoFactorForm"));
    });
    const setupOtp = UI.bindOtpInput($("setupCode"), document.querySelector("#setupCodeGroup .auth-otp-slots"), function () {
        submitForm($("setupForm"));
    });
    const passwordState = { policy: null, identity: {} };
    const feedback = UI.bindPasswordFeedback({
        input: $("newPassword"),
        confirm: $("confirmPassword"),
        meter: $("strengthMeter"),
        label: $("strengthLabel"),
        rules: $("passwordRules"),
        getPolicy: function () { return passwordState.policy; },
        getIdentity: function () { return passwordState.identity; },
    });

    let backupMode = false;
    function setBackupMode(enabled) {
        backupMode = enabled;
        $("totpGroup").hidden = enabled;
        $("backupCodeField").hidden = !enabled;
        $("toggleBackupBtn").textContent = enabled ? "Use the authenticator app instead" : "Use a backup code instead";
        $("twoFactorIntro").textContent = enabled
            ? "Enter one of the backup codes you saved when you set up two-step verification."
            : "Open your authenticator app and enter the 6-digit code for ISATU Visitor Management.";
        showMessage($("twoFactorMessage"), "");
        window.setTimeout(function () {
            if (enabled) {
                $("backupCode").focus();
            } else {
                totp.focus();
            }
        }, 30);
    }
    $("toggleBackupBtn").addEventListener("click", function () {
        setBackupMode(!backupMode);
    });

    // An error stays only until the user changes what it was about.
    [
        ["loginEmail", "loginMessage"],
        ["loginPassword", "loginMessage"],
        ["totpCode", "twoFactorMessage"],
        ["backupCode", "twoFactorMessage"],
        ["newPassword", "passwordMessage"],
        ["confirmPassword", "passwordMessage"],
        ["setupCode", "setupMessage"],
    ].forEach(function (pair) {
        $(pair[0]).addEventListener("input", function () {
            showMessage($(pair[1]), "");
        });
    });

    function resetStepPanels() {
        totp.clear();
        setupOtp.clear();
        $("backupCode").value = "";
        $("newPassword").value = "";
        $("confirmPassword").value = "";
        $("trustDevice").checked = false;
        feedback.update();
        ["twoFactorMessage", "passwordMessage", "setupMessage"].forEach(function (id) {
            showMessage($(id), "");
        });
        if (backupMode) {
            setBackupMode(false);
        }
    }

    function openStep(name, data) {
        if (name === "twoFactor") {
            showPanel("twoFactor");
            setBackupMode(false);
            return;
        }
        if (name === "password") {
            passwordState.policy = data.policy || null;
            passwordState.identity = { username: data.username, email: data.email, display_name: data.display_name };
            $("passwordUsername").value = data.username || "";
            $("passwordIntro").textContent = data.reason === "temporary"
                ? "You signed in with a temporary password. Choose your own password to continue."
                : "This account still uses the default password. Choose a new password only you know.";
            feedback.update();
            showPanel("password");
            window.setTimeout(function () { $("newPassword").focus(); }, 30);
            return;
        }
        $("setupIntro").textContent = data.role === "admin"
            ? "Administrator accounts need a code from an authenticator app each time they sign in."
            : "Your account needs a code from an authenticator app each time you sign in.";
        showPanel("setup");
        loadSetup();
    }

    async function loadSetup() {
        $("setupSecret").textContent = "····";
        $("setupQr").hidden = false;
        $("setupQrFallback").hidden = true;
        $("setupBtn").disabled = true;
        const result = await api("login_two_factor_setup.php", null, "GET");
        if (!result.data.success) {
            stepFailed(result, { message: $("setupMessage") });
            return;
        }
        $("setupSecret").textContent = result.data.secret;
        $("setupAccount").textContent = result.data.account;
        $("setupBtn").disabled = false;
        const drawn = await UI.renderQr($("setupQr"), result.data.otpauth_uri);
        if (!drawn) {
            $("setupQr").hidden = true;
            $("setupQrFallback").hidden = false;
            $("setupManualKey").open = true;
        }
        setupOtp.focus();
    }

    $("copySecretBtn").addEventListener("click", function () {
        const button = this;
        UI.copyText($("setupSecret").textContent.replace(/\s+/g, "")).then(function (copied) {
            button.textContent = copied ? "Copied" : "Copy failed";
            window.setTimeout(function () { button.textContent = "Copy"; }, 1800);
        });
    });

    function withButton(button, label, task) {
        const original = button.textContent;
        button.disabled = true;
        button.textContent = label;
        return Promise.resolve().then(task).finally(function () {
            button.disabled = false;
            button.textContent = original;
        });
    }

    $("twoFactorForm").addEventListener("submit", function (event) {
        event.preventDefault();
        if (busy) {
            return;
        }
        const code = backupMode ? $("backupCode").value.trim() : totp.value();
        if (backupMode ? code === "" : code.length !== 6) {
            showMessage($("twoFactorMessage"), backupMode ? "Enter one of your backup codes." : "Enter the 6-digit code from your app.");
            return;
        }
        busy = true;
        showMessage($("twoFactorMessage"), "");
        withButton($("twoFactorBtn"), "Verifying…", async function () {
            const result = await api("login_two_factor.php", {
                method: backupMode ? "backup_code" : "totp",
                code: code,
                trust_device: $("trustDevice").checked,
            });
            if (result.data.success) {
                await route(result, "step");
            } else {
                stepFailed(result, { message: $("twoFactorMessage"), otp: backupMode ? null : totp });
            }
        }).finally(function () { busy = false; });
    });

    $("passwordForm").addEventListener("submit", function (event) {
        event.preventDefault();
        if (busy) {
            return;
        }
        const problem = feedback.firstProblem();
        if (problem) {
            showMessage($("passwordMessage"), problem);
            return;
        }
        busy = true;
        showMessage($("passwordMessage"), "");
        withButton($("passwordBtn"), "Saving…", async function () {
            const result = await api("login_change_password.php", {
                new_password: $("newPassword").value,
                confirm_password: $("confirmPassword").value,
            });
            if (result.data.success) {
                await route(result, "step");
            } else {
                stepFailed(result, { message: $("passwordMessage") });
            }
        }).finally(function () { busy = false; });
    });

    $("setupForm").addEventListener("submit", function (event) {
        event.preventDefault();
        if (busy || $("setupBtn").disabled) {
            return;
        }
        const code = setupOtp.value();
        if (code.length !== 6) {
            showMessage($("setupMessage"), "Enter the 6-digit code from your app.");
            return;
        }
        busy = true;
        showMessage($("setupMessage"), "");
        withButton($("setupBtn"), "Verifying…", async function () {
            const result = await api("login_two_factor_setup.php", { code: code });
            if (result.data.success) {
                await route(result, "step");
            } else {
                stepFailed(result, { message: $("setupMessage"), otp: setupOtp });
            }
        }).finally(function () { busy = false; });
    });

    function showBackupCodes(codes, account) {
        return new Promise(function (resolve) {
            const list = $("backupCodesList");
            list.replaceChildren();
            codes.forEach(function (code) {
                const item = document.createElement("li");
                const text = document.createElement("code");
                text.textContent = code;
                item.appendChild(text);
                list.appendChild(item);
            });
            const continueBtn = $("continueAfterCodesBtn");
            const savedCheck = $("savedCodesCheck");
            savedCheck.checked = false;
            continueBtn.disabled = true;
            savedCheck.onchange = function () {
                continueBtn.disabled = !savedCheck.checked;
            };
            $("copyCodesBtn").onclick = function () {
                const button = this;
                UI.copyText(UI.backupCodesText(codes, account)).then(function (copied) {
                    button.textContent = copied ? "Copied" : "Copy failed";
                    window.setTimeout(function () { button.textContent = "Copy codes"; }, 1800);
                });
            };
            $("downloadCodesBtn").onclick = function () {
                UI.downloadText("isatu-vms-backup-codes-" + account + ".txt", UI.backupCodesText(codes, account));
                savedCheck.focus();
            };
            continueBtn.onclick = function () {
                continueBtn.disabled = true;
                resolve();
            };
            showPanel("backup");
        });
    }

    document.querySelectorAll("[data-cancel-signin]").forEach(function (button) {
        button.addEventListener("click", function () {
            api("logout.php", {}).then(function () {
                returnToSignIn();
                showNotice("Sign-in cancelled.", "info");
            });
        });
    });

    /* ---------- On load ---------- */

    const notice = Auth.takeNotice();
    if (notice && notice.message) {
        showNotice(notice.message, notice.code === "signed_out" ? "success" : "warning");
    }

    fetch("session_status.php", { credentials: "same-origin", cache: "no-store" })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data && data.authenticated && data.role) {
                Auth.setSessionFromLogin(data);
                window.location.replace(ROLE_HOME[data.role] || "index.html");
                return;
            }
            const reasons = {
                idle_timeout: "You were signed out after a period of inactivity.",
                session_expired: "Your session expired. Please sign in again.",
                session_revoked: "Your session was ended. Please sign in again.",
            };
            if (!notice && data && reasons[data.reason]) {
                showNotice(reasons[data.reason], "warning");
            }
        })
        .catch(function () {});

    usernameInput.focus();
})();
