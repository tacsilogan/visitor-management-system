/*
 * Staff "Account security" panel (profile menu) and the shared "Confirm it's you"
 * dialog used before sensitive actions. Needs auth.js and auth_ui.js first.
 */
(function () {
    "use strict";

    const Auth = window.PhoneTrackerAuth;
    const UI = window.PhoneTrackerAuthUI;
    if (!Auth || !UI) {
        return;
    }

    const STEP_UP_DIALOG = `
<dialog class="admin-dialog stepup-dialog" id="stepUpDialog" aria-labelledby="stepUpTitle">
    <form id="stepUpForm" novalidate>
        <div class="admin-dialog-header">
            <div>
                <p class="admin-eyebrow">Security check</p>
                <h2 id="stepUpTitle">Confirm it's you</h2>
            </div>
            <button class="admin-dialog-close" type="button" data-stepup-cancel aria-label="Close">×</button>
        </div>
        <p class="stepup-intro">This action needs a quick check. You won't be asked again for 15 minutes.</p>
        <div class="stepup-tabs" role="tablist" id="stepUpTabs" hidden>
            <button type="button" role="tab" data-stepup-method="password" aria-selected="true">Password</button>
            <button type="button" role="tab" data-stepup-method="authenticator" aria-selected="false">Authenticator code</button>
        </div>
        <label class="acct-field" id="stepUpPasswordField">
            <span>Your password</span>
            <input type="password" id="stepUpPassword" autocomplete="current-password">
        </label>
        <label class="acct-field" id="stepUpCodeField" hidden>
            <span>6-digit code from your authenticator app</span>
            <input type="text" id="stepUpCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" spellcheck="false">
        </label>
        <p class="admin-message is-error" id="stepUpError" role="alert" hidden></p>
        <div class="admin-dialog-actions">
            <button class="admin-cancel-button" type="button" data-stepup-cancel>Cancel</button>
            <button class="admin-primary-button" type="submit" id="stepUpSubmit">Confirm</button>
        </div>
    </form>
</dialog>`;

    const ACCOUNT_DIALOG = `
<dialog class="admin-dialog acct-dialog" id="accountSecurityDialog" aria-labelledby="acctTitle">
    <div class="acct-shell">
        <div class="admin-dialog-header">
            <div>
                <p class="admin-eyebrow">Your account</p>
                <h2 id="acctTitle">Account security</h2>
            </div>
            <button class="admin-dialog-close" type="button" data-acct-close aria-label="Close">×</button>
        </div>
        <p class="acct-loading" id="acctLoading">Loading your security settings…</p>
        <p class="admin-message is-error" id="acctLoadError" role="alert" hidden></p>
        <div id="acctBody" hidden>
            <div class="acct-summary">
                <div class="acct-summary-item" id="acctSumPassword"><span class="acct-dot"></span><div><strong>Password</strong><small></small></div></div>
                <div class="acct-summary-item" id="acctSumTwoFactor"><span class="acct-dot"></span><div><strong>Two-step verification</strong><small></small></div></div>
                <div class="acct-summary-item" id="acctSumSession"><span class="acct-dot"></span><div><strong>This session</strong><small></small></div></div>
            </div>

            <section class="acct-section" aria-labelledby="acctPasswordTitle">
                <div class="acct-section-head">
                    <div>
                        <h3 id="acctPasswordTitle">Password</h3>
                        <p id="acctPasswordMeta"></p>
                    </div>
                    <button class="admin-cancel-button" type="button" id="acctPasswordToggle">Change password</button>
                </div>
                <p class="admin-message is-success" id="acctPasswordSuccess" role="status" hidden></p>
                <form class="acct-form" id="acctPasswordForm" novalidate hidden>
                    <input type="text" id="acctUsername" autocomplete="username" hidden>
                    <label class="acct-field">
                        <span>Current password</span>
                        <input type="password" id="acctCurrentPassword" autocomplete="current-password">
                    </label>
                    <label class="acct-field">
                        <span>New password</span>
                        <input type="password" id="acctNewPassword" autocomplete="new-password">
                    </label>
                    <div class="auth-strength acct-strength" id="acctStrength" data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                    <p class="auth-strength-label" id="acctStrengthLabel">Password strength</p>
                    <ul class="auth-rules acct-rules" id="acctRules">
                        <li data-rule="length" data-state="idle">At least 10 characters</li>
                        <li data-rule="mix" data-state="idle">A letter and a number</li>
                        <li data-rule="common" data-state="idle">Not a common or easy-to-guess password</li>
                        <li data-rule="identity" data-state="idle">Doesn't include your username or name</li>
                        <li data-rule="match" data-state="idle">Both passwords match</li>
                    </ul>
                    <label class="acct-field">
                        <span>Confirm new password</span>
                        <input type="password" id="acctConfirmPassword" autocomplete="new-password">
                    </label>
                    <p class="acct-note">Changing your password signs you out on your other devices.</p>
                    <p class="admin-message is-error" id="acctPasswordError" role="alert" hidden></p>
                    <div class="acct-actions">
                        <button class="admin-cancel-button" type="button" id="acctPasswordCancel">Cancel</button>
                        <button class="admin-primary-button" type="submit" id="acctPasswordSave">Save new password</button>
                    </div>
                </form>
            </section>

            <section class="acct-section" aria-labelledby="acctTwoFactorTitle">
                <div class="acct-section-head">
                    <div>
                        <h3 id="acctTwoFactorTitle">Two-step verification <span class="acct-pill" id="acctTwoFactorPill"></span></h3>
                        <p id="acctTwoFactorMeta"></p>
                    </div>
                    <div class="acct-head-actions" id="acctTwoFactorActions"></div>
                </div>
                <p class="admin-message is-error" id="acctTwoFactorError" role="alert" hidden></p>
                <p class="admin-message is-success" id="acctTwoFactorSuccess" role="status" hidden></p>
                <div class="acct-setup" id="acctSetup" hidden>
                    <ol class="acct-setup-steps">
                        <li>Install <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong>.</li>
                        <li>Add an account in the app and scan the QR code, or enter the key.</li>
                        <li>Type the 6-digit code the app shows.</li>
                    </ol>
                    <div class="acct-setup-grid">
                        <div class="auth-qr-frame"><div class="auth-qr" id="acctQr" role="img" aria-label="QR code for your authenticator app"></div></div>
                        <div class="acct-setup-side">
                            <p class="acct-key-label">Setup key</p>
                            <div class="auth-key-row"><code id="acctSecret"></code><button class="auth-copy-button" type="button" id="acctCopySecret">Copy</button></div>
                            <p class="acct-qr-fallback" id="acctQrFallback" hidden>The QR code needs an internet connection. Enter the key instead.</p>
                            <form id="acctSetupForm" novalidate>
                                <div class="auth-otp acct-otp" id="acctOtpGroup">
                                    <input id="acctSetupCode" class="auth-otp-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" spellcheck="false" aria-label="6-digit code from your authenticator app">
                                    <div class="auth-otp-slots" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>
                                </div>
                                <div class="acct-actions">
                                    <button class="admin-cancel-button" type="button" id="acctSetupCancel">Cancel</button>
                                    <button class="admin-primary-button" type="submit" id="acctSetupVerify">Verify and turn on</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="acct-codes" id="acctCodes" hidden>
                    <p><strong>Save these backup codes.</strong> Each one signs you in once if you lose your phone. They won't be shown again.</p>
                    <ol class="auth-backup-grid" id="acctCodesList"></ol>
                    <div class="acct-actions">
                        <button class="auth-secondary-button" type="button" id="acctCopyCodes">Copy codes</button>
                        <button class="auth-secondary-button" type="button" id="acctDownloadCodes">Download .txt</button>
                        <button class="admin-primary-button" type="button" id="acctCodesDone">I saved them</button>
                    </div>
                </div>
            </section>

            <section class="acct-section" aria-labelledby="acctDevicesTitle">
                <div class="acct-section-head">
                    <div>
                        <h3 id="acctDevicesTitle">Trusted browsers</h3>
                        <p>Browsers where you chose "Don't ask again for 30 days".</p>
                    </div>
                    <button class="admin-cancel-button" type="button" id="acctForgetAll">Forget all</button>
                </div>
                <ul class="acct-list" id="acctDevices"></ul>
                <p class="acct-empty" id="acctDevicesEmpty">No trusted browsers. Two-step verification is asked at every sign-in.</p>
            </section>

            <section class="acct-section" aria-labelledby="acctSessionTitle">
                <div class="acct-section-head">
                    <div>
                        <h3 id="acctSessionTitle">Sessions</h3>
                        <p id="acctSessionMeta"></p>
                    </div>
                    <button class="admin-cancel-button" type="button" id="acctSignOutOthers">Sign out everywhere else</button>
                </div>
                <p class="admin-message is-success" id="acctSessionSuccess" role="status" hidden></p>
            </section>

            <section class="acct-section" aria-labelledby="acctActivityTitle">
                <div class="acct-section-head">
                    <div>
                        <h3 id="acctActivityTitle">Recent sign-in activity</h3>
                        <p>If you don't recognise an entry, change your password.</p>
                    </div>
                </div>
                <ul class="acct-list acct-activity" id="acctActivity"></ul>
            </section>
        </div>
    </div>
</dialog>`;

    document.body.insertAdjacentHTML("beforeend", STEP_UP_DIALOG + ACCOUNT_DIALOG);
    const $ = function (id) { return document.getElementById(id); };

    function show(element, message) {
        element.textContent = message || "";
        element.hidden = !message;
    }

    function request(url, body) {
        const options = { credentials: "same-origin", cache: "no-store" };
        if (body) {
            options.method = "POST";
            options.headers = { "Content-Type": "application/json" };
            options.body = JSON.stringify(body);
        }
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                data = data || {};
                data.httpStatus = response.status;
                return data;
            });
        }, function () {
            return { success: false, message: "Can't reach the server. Please try again.", httpStatus: 0 };
        });
    }

    /* ---------- Confirm it's you ---------- */

    let stepUpResolve = null;
    let stepUpMethod = "password";

    function setStepUpMethod(method) {
        stepUpMethod = method;
        $("stepUpPasswordField").hidden = method !== "password";
        $("stepUpCodeField").hidden = method !== "authenticator";
        document.querySelectorAll("[data-stepup-method]").forEach(function (tab) {
            const active = tab.getAttribute("data-stepup-method") === method;
            tab.classList.toggle("is-active", active);
            tab.setAttribute("aria-selected", String(active));
        });
        show($("stepUpError"), "");
        window.setTimeout(function () {
            (method === "password" ? $("stepUpPassword") : $("stepUpCode")).focus();
        }, 30);
    }

    function finishStepUp(confirmed) {
        if ($("stepUpDialog").open) {
            $("stepUpDialog").close();
        }
        if (stepUpResolve) {
            const resolve = stepUpResolve;
            stepUpResolve = null;
            resolve(confirmed);
        }
    }

    /** Asks for the password (or authenticator code); resolves true when the server accepts it. */
    function confirmIdentity(methods) {
        const allowed = Array.isArray(methods) && methods.length ? methods : ["password"];
        if (stepUpResolve) {
            finishStepUp(false);
        }
        return new Promise(function (resolve) {
            stepUpResolve = resolve;
            $("stepUpPassword").value = "";
            $("stepUpCode").value = "";
            $("stepUpTabs").hidden = allowed.indexOf("authenticator") === -1;
            $("stepUpDialog").showModal();
            setStepUpMethod("password");
        });
    }

    document.querySelectorAll("[data-stepup-method]").forEach(function (tab) {
        tab.addEventListener("click", function () {
            setStepUpMethod(tab.getAttribute("data-stepup-method"));
        });
    });
    document.querySelectorAll("[data-stepup-cancel]").forEach(function (button) {
        button.addEventListener("click", function () { finishStepUp(false); });
    });
    $("stepUpDialog").addEventListener("cancel", function (event) {
        event.preventDefault();
        finishStepUp(false);
    });
    $("stepUpForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        const submit = $("stepUpSubmit");
        const value = stepUpMethod === "password" ? $("stepUpPassword").value : $("stepUpCode").value.trim();
        if (!value) {
            show($("stepUpError"), stepUpMethod === "password" ? "Enter your password." : "Enter the 6-digit code.");
            return;
        }
        submit.disabled = true;
        const data = await request("auth_step_up.php", stepUpMethod === "password"
            ? { method: "password", password: value }
            : { method: "authenticator", code: value });
        submit.disabled = false;
        if (data.success) {
            finishStepUp(true);
            return;
        }
        let message = data.message || "That didn't work. Please try again.";
        if (typeof data.attempts_remaining === "number" && data.attempts_remaining <= 2) {
            message += " " + (data.attempts_remaining === 1 ? "1 attempt" : data.attempts_remaining + " attempts") + " left before you're signed out.";
        }
        show($("stepUpError"), message);
        (stepUpMethod === "password" ? $("stepUpPassword") : $("stepUpCode")).select();
    });

    /**
     * Runs a request; if the server answers "step_up_required", asks the user to
     * confirm and runs it once more. `run` must resolve to the parsed JSON.
     */
    async function withStepUp(run) {
        let data = await run();
        if (data && data.code === "step_up_required") {
            const confirmed = await confirmIdentity(data.methods);
            if (!confirmed) {
                return { success: false, cancelled: true, message: "" };
            }
            data = await run();
        }
        return data;
    }

    window.PhoneTrackerSecurity = {
        confirmIdentity: confirmIdentity,
        withStepUp: withStepUp,
        open: openAccountSecurity,
    };

    /* ---------- Account security panel ---------- */

    const dialog = $("accountSecurityDialog");
    let overview = null;

    const passwordFeedback = UI.bindPasswordFeedback({
        input: $("acctNewPassword"),
        confirm: $("acctConfirmPassword"),
        meter: $("acctStrength"),
        label: $("acctStrengthLabel"),
        rules: $("acctRules"),
        getPolicy: function () { return overview && overview.password_policy; },
        getIdentity: function () { return overview && overview.identity; },
    });
    const setupOtp = UI.bindOtpInput($("acctSetupCode"), document.querySelector("#acctOtpGroup .auth-otp-slots"), function () {
        if (typeof $("acctSetupForm").requestSubmit === "function") {
            $("acctSetupForm").requestSubmit();
        }
    });

    function methodLabel(method) {
        const labels = {
            "password": "password",
            "password+authenticator": "password and authenticator code",
            "password+backup_code": "password and a backup code",
            "password+trusted_device": "password on a trusted browser",
        };
        return labels[method] || "password";
    }

    function summary(id, tone, text) {
        const item = $(id);
        item.dataset.tone = tone;
        item.querySelector("small").textContent = text;
    }

    function actionButton(label, className, handler) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = className;
        button.textContent = label;
        button.addEventListener("click", handler);
        return button;
    }

    function render() {
        const account = overview.account;
        const twoFactor = overview.two_factor;
        const session = overview.session;
        $("acctUsername").value = account.username;

        $("acctPasswordMeta").textContent = account.password_changed_at
            ? "Last changed " + UI.formatRelative(account.password_changed_at) + "."
            : "Not changed since the account was created.";
        summary("acctSumPassword", "good", account.password_changed_at ? "Changed " + UI.formatRelative(account.password_changed_at) : "Set when the account was created");

        const pill = $("acctTwoFactorPill");
        pill.textContent = twoFactor.enabled ? "On" : "Off";
        pill.dataset.tone = twoFactor.enabled ? "good" : "warn";
        if (twoFactor.enabled) {
            $("acctTwoFactorMeta").textContent = "Turned on " + UI.formatDateTime(twoFactor.enabled_at) + ". "
                + twoFactor.backup_codes_remaining + (twoFactor.backup_codes_remaining === 1 ? " backup code" : " backup codes") + " left."
                + (twoFactor.required ? " Required for your role." : "");
            summary("acctSumTwoFactor", twoFactor.backup_codes_remaining <= 2 ? "warn" : "good",
                twoFactor.backup_codes_remaining <= 2 ? "On · only " + twoFactor.backup_codes_remaining + " backup codes left" : "On");
        } else {
            $("acctTwoFactorMeta").textContent = "Add a code from your phone to every sign-in, so a stolen password alone can't open your account.";
            summary("acctSumTwoFactor", "warn", "Off — turn it on for better protection");
        }
        const actions = $("acctTwoFactorActions");
        actions.replaceChildren();
        if (twoFactor.enabled) {
            actions.appendChild(actionButton("New backup codes", "admin-cancel-button", regenerateCodes));
            const off = actionButton("Turn off", "admin-cancel-button acct-danger", disableTwoFactor);
            off.disabled = twoFactor.required;
            off.title = twoFactor.required ? "Required for your role" : "";
            actions.appendChild(off);
        } else if (twoFactor.allowed) {
            actions.appendChild(actionButton("Turn on", "admin-primary-button", beginSetup));
        }

        summary("acctSumSession", "good", "Signed in with " + methodLabel(session.auth_method));
        const idleMinutes = Math.round((session.idle_timeout_seconds || 0) / 60);
        $("acctSessionMeta").textContent = "Signed in " + UI.formatRelative(session.login_at) + " with " + methodLabel(session.auth_method)
            + (idleMinutes > 0
                ? ". You're signed out after " + idleMinutes + " minutes of inactivity"
                : ". You stay signed in on this device until you sign out")
            + (session.expires_at ? ", until " + UI.formatDateTime(session.expires_at) + " at the latest." : ".");

        const devices = $("acctDevices");
        devices.replaceChildren();
        overview.trusted_devices.forEach(function (device) {
            const item = document.createElement("li");
            const text = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = device.label || "Browser";
            if (device.current) {
                const badge = document.createElement("span");
                badge.className = "acct-pill";
                badge.dataset.tone = "info";
                badge.textContent = "This browser";
                title.appendChild(badge);
            }
            const meta = document.createElement("small");
            meta.textContent = "Last used " + UI.formatRelative(device.last_used_at || device.created_at) + " · " + UI.formatIp(device.ip)
                + " · trusted until " + UI.formatDateTime(device.expires_at);
            text.append(title, meta);
            item.append(text, actionButton("Forget", "admin-text-action", function () { forgetDevices(device.id); }));
            devices.appendChild(item);
        });
        $("acctDevicesEmpty").hidden = overview.trusted_devices.length > 0;
        $("acctForgetAll").hidden = overview.trusted_devices.length === 0;

        const activity = $("acctActivity");
        activity.replaceChildren();
        overview.recent_activity.forEach(function (attempt) {
            const item = document.createElement("li");
            item.dataset.outcome = attempt.outcome;
            const icon = document.createElement("span");
            icon.className = "acct-activity-icon";
            icon.setAttribute("aria-hidden", "true");
            const text = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = UI.describeAttempt(attempt);
            const meta = document.createElement("small");
            meta.textContent = attempt.device + " · " + UI.formatIp(attempt.ip) + (attempt.channel === "mobile" ? " · visitor app" : "");
            text.append(title, meta);
            const time = document.createElement("time");
            time.textContent = UI.formatRelative(attempt.created_at);
            time.title = UI.formatDateTime(attempt.created_at);
            item.append(icon, text, time);
            activity.appendChild(item);
        });
        if (!overview.recent_activity.length) {
            const empty = document.createElement("li");
            empty.className = "acct-empty";
            empty.textContent = "No sign-in activity yet.";
            activity.appendChild(empty);
        }
        updateMenuHint();
    }

    async function loadOverview() {
        const data = await request("account_security.php");
        $("acctLoading").hidden = true;
        if (!data.success) {
            show($("acctLoadError"), data.message || "Could not load your security settings.");
            return;
        }
        show($("acctLoadError"), "");
        overview = data;
        $("acctBody").hidden = false;
        render();
    }

    function resetTransient() {
        ["acctPasswordSuccess", "acctPasswordError", "acctTwoFactorError", "acctTwoFactorSuccess", "acctSessionSuccess"].forEach(function (id) {
            show($(id), "");
        });
        closePasswordForm();
        $("acctSetup").hidden = true;
        $("acctCodes").hidden = true;
        $("acctTwoFactorActions").hidden = false;
        setupOtp.clear();
    }

    function openAccountSecurity() {
        resetTransient();
        $("acctLoading").hidden = Boolean(overview);
        if (!dialog.open) {
            dialog.showModal();
        }
        loadOverview();
    }

    dialog.querySelector("[data-acct-close]").addEventListener("click", function () { dialog.close(); });
    dialog.addEventListener("click", function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    /* Password */
    function closePasswordForm() {
        $("acctPasswordForm").hidden = true;
        $("acctPasswordToggle").hidden = false;
        ["acctCurrentPassword", "acctNewPassword", "acctConfirmPassword"].forEach(function (id) { $(id).value = ""; });
        passwordFeedback.update();
    }
    $("acctPasswordToggle").addEventListener("click", function () {
        show($("acctPasswordSuccess"), "");
        $("acctPasswordForm").hidden = false;
        $("acctPasswordToggle").hidden = true;
        $("acctCurrentPassword").focus();
    });
    $("acctPasswordCancel").addEventListener("click", closePasswordForm);
    ["acctCurrentPassword", "acctNewPassword", "acctConfirmPassword"].forEach(function (id) {
        $(id).addEventListener("input", function () { show($("acctPasswordError"), ""); });
    });
    $("acctPasswordForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        if (!$("acctCurrentPassword").value) {
            show($("acctPasswordError"), "Enter your current password.");
            return;
        }
        const problem = passwordFeedback.firstProblem();
        if (problem) {
            show($("acctPasswordError"), problem);
            return;
        }
        $("acctPasswordSave").disabled = true;
        const data = await request("account_security.php", {
            action: "change_password",
            current_password: $("acctCurrentPassword").value,
            new_password: $("acctNewPassword").value,
            confirm_password: $("acctConfirmPassword").value,
        });
        $("acctPasswordSave").disabled = false;
        if (!data.success) {
            let message = data.message || "Could not change your password.";
            if (typeof data.attempts_remaining === "number" && data.attempts_remaining <= 2) {
                message += " " + (data.attempts_remaining === 1 ? "1 attempt" : data.attempts_remaining + " attempts") + " left before you're signed out.";
            }
            show($("acctPasswordError"), message);
            return;
        }
        closePasswordForm();
        show($("acctPasswordSuccess"), data.message);
        loadOverview();
    });

    /* Two-step verification */
    async function beginSetup() {
        show($("acctTwoFactorError"), "");
        show($("acctTwoFactorSuccess"), "");
        const data = await withStepUp(function () { return request("account_security.php", { action: "two_factor_begin" }); });
        if (!data.success) {
            show($("acctTwoFactorError"), data.cancelled ? "" : data.message);
            return;
        }
        $("acctSecret").textContent = data.secret;
        $("acctQr").hidden = false;
        $("acctQrFallback").hidden = true;
        $("acctSetup").hidden = false;
        $("acctTwoFactorActions").hidden = true;
        setupOtp.clear();
        const drawn = await UI.renderQr($("acctQr"), data.otpauth_uri);
        if (!drawn) {
            $("acctQr").hidden = true;
            $("acctQrFallback").hidden = false;
        }
        setupOtp.focus();
    }

    function endSetup() {
        $("acctSetup").hidden = true;
        $("acctTwoFactorActions").hidden = false;
        setupOtp.clear();
    }
    $("acctSetupCancel").addEventListener("click", endSetup);
    $("acctCopySecret").addEventListener("click", function () {
        const button = this;
        UI.copyText($("acctSecret").textContent.replace(/\s+/g, "")).then(function (copied) {
            button.textContent = copied ? "Copied" : "Copy failed";
            window.setTimeout(function () { button.textContent = "Copy"; }, 1800);
        });
    });
    $("acctSetupCode").addEventListener("input", function () { show($("acctTwoFactorError"), ""); });

    let setupBusy = false;
    $("acctSetupForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        const code = setupOtp.value();
        if (setupBusy) {
            return;
        }
        if (code.length !== 6) {
            show($("acctTwoFactorError"), "Enter the 6-digit code from your app.");
            return;
        }
        setupBusy = true;
        const data = await withStepUp(function () { return request("account_security.php", { action: "two_factor_confirm", code: code }); });
        setupBusy = false;
        if (!data.success) {
            if (!data.cancelled) {
                show($("acctTwoFactorError"), data.message || "That code didn't match.");
                setupOtp.invalid();
                setupOtp.focus();
            }
            return;
        }
        endSetup();
        showCodes(data.backup_codes, "Two-step verification is on.");
    });

    let shownCodes = [];
    function showCodes(codes, successMessage) {
        shownCodes = codes;
        const list = $("acctCodesList");
        list.replaceChildren();
        codes.forEach(function (code) {
            const item = document.createElement("li");
            const text = document.createElement("code");
            text.textContent = code;
            item.appendChild(text);
            list.appendChild(item);
        });
        $("acctCodes").hidden = false;
        $("acctTwoFactorActions").hidden = true;
        show($("acctTwoFactorSuccess"), successMessage);
    }
    $("acctCopyCodes").addEventListener("click", function () {
        const button = this;
        UI.copyText(UI.backupCodesText(shownCodes, overview.account.username)).then(function (copied) {
            button.textContent = copied ? "Copied" : "Copy failed";
            window.setTimeout(function () { button.textContent = "Copy codes"; }, 1800);
        });
    });
    $("acctDownloadCodes").addEventListener("click", function () {
        UI.downloadText("isatu-vms-backup-codes-" + overview.account.username + ".txt", UI.backupCodesText(shownCodes, overview.account.username));
    });
    $("acctCodesDone").addEventListener("click", function () {
        shownCodes = [];
        $("acctCodes").hidden = true;
        $("acctTwoFactorActions").hidden = false;
        loadOverview();
    });

    async function regenerateCodes() {
        if (!(await AppDialog.confirm({
            title: "Create new backup codes?",
            message: "Your old backup codes will stop working.",
            confirmLabel: "Create new codes",
        }))) {
            return;
        }
        show($("acctTwoFactorError"), "");
        const data = await withStepUp(function () { return request("account_security.php", { action: "backup_codes_regenerate" }); });
        if (!data.success) {
            show($("acctTwoFactorError"), data.cancelled ? "" : data.message);
            return;
        }
        showCodes(data.backup_codes, "New backup codes created. The old ones no longer work.");
    }

    async function disableTwoFactor() {
        if (!(await AppDialog.confirm({
            title: "Turn off two-step verification?",
            message: "Your account will be protected by your password only.",
            confirmLabel: "Turn off",
            danger: true,
        }))) {
            return;
        }
        show($("acctTwoFactorError"), "");
        const data = await withStepUp(function () { return request("account_security.php", { action: "two_factor_disable" }); });
        if (!data.success) {
            show($("acctTwoFactorError"), data.cancelled ? "" : data.message);
            return;
        }
        show($("acctTwoFactorSuccess"), data.message);
        loadOverview();
    }

    /* Trusted browsers and sessions */
    async function forgetDevices(deviceId) {
        const body = { action: "trusted_devices_forget" };
        if (deviceId) {
            body.device_id = deviceId;
        }
        await request("account_security.php", body);
        loadOverview();
    }
    $("acctForgetAll").addEventListener("click", async function () {
        if (await AppDialog.confirm({
            title: "Forget all trusted browsers?",
            message: "Each one will ask for a two-step code again.",
            confirmLabel: "Forget browsers",
        })) {
            forgetDevices(null);
        }
    });
    $("acctSignOutOthers").addEventListener("click", async function () {
        if (!(await AppDialog.confirm({
            title: "Sign out of every other browser and device?",
            message: "This one stays signed in.",
            confirmLabel: "Sign out others",
        }))) {
            return;
        }
        const data = await request("account_security.php", { action: "sign_out_others" });
        show($("acctSessionSuccess"), data.success ? data.message : (data.message || "Could not sign out other sessions."));
    });

    /* ---------- Profile menu entry ---------- */

    const menu = document.querySelector(".admin-profile-menu");
    let menuHint = null;
    if (menu) {
        const signOut = menu.querySelector("#logoutBtn, #officeLogoutBtn");
        const button = document.createElement("button");
        button.type = "button";
        button.className = "acct-menu-button";
        button.id = "accountSecurityBtn";
        button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 3 8.4 7 9.5 4-1.1 7-5 7-9.5V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg><span>Account security<small></small></span>';
        menuHint = button.querySelector("small");
        menu.insertBefore(button, signOut || null);
        button.addEventListener("click", function () {
            menu.hidden = true;
            const toggle = document.querySelector("[aria-controls='" + menu.id + "']") || document.getElementById("officeProfileBtn");
            if (toggle) {
                toggle.setAttribute("aria-expanded", "false");
            }
            openAccountSecurity();
        });
    }

    function updateMenuHint(twoFactorEnabled) {
        if (!menuHint) {
            return;
        }
        const enabled = typeof twoFactorEnabled === "boolean" ? twoFactorEnabled : Boolean(overview && overview.two_factor.enabled);
        menuHint.textContent = enabled ? "Two-step verification on" : "Two-step verification off";
        menuHint.dataset.tone = enabled ? "good" : "warn";
    }

    document.addEventListener("auth:session", function (event) {
        if (event.detail && typeof event.detail.two_factor_enabled === "boolean") {
            updateMenuHint(event.detail.two_factor_enabled);
        }
    });
})();
