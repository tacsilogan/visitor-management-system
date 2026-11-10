(function () {
    "use strict";

    const ROLE_KEY = "phone_tracker_role";
    const USER_ID_KEY = "phone_tracker_user_id";
    const USERNAME_KEY = "phone_tracker_username";
    const DISPLAY_NAME_KEY = "phone_tracker_display_name";
    const PERMISSIONS_KEY = "phone_tracker_permissions";
    const NOTICE_KEY = "phone_tracker_auth_notice";
    const CSRF_COOKIE = "ISATU_VMS_CSRF";
    const ALLOWED_ROLES = ["security", "visitor", "offices", "admin"];
    const SAFE_METHODS = ["GET", "HEAD", "OPTIONS"];
    // X-Auth-Error codes that mean the server ended this browser's session.
    const SESSION_END_MESSAGES = {
        not_authenticated: "Please sign in to continue.",
        idle_timeout: "You were signed out after a period of inactivity.",
        session_expired: "Your session expired. Please sign in again.",
        session_revoked: "Your session was ended. Please sign in again.",
        locked: "Too many failed attempts. You were signed out to protect your account.",
    };

    const script = document.currentScript;
    const BASE = new URL(".", script && script.src ? script.src : window.location.href);
    const LOGIN_URL = new URL("login.html", BASE).href;
    const STATUS_URL = new URL("session_status.php", BASE).href;
    const isLoginPage = /(^|\/)login\.html$/i.test(window.location.pathname);

    // Older links carried the role in the URL. The server decides the role, so drop it.
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.has("role")) {
            params.delete("role");
            const query = params.toString();
            history.replaceState(null, "", window.location.pathname + (query ? "?" + query : "") + window.location.hash);
        }
    } catch (error) {}

    function readStore(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeStore(key, value) {
        try {
            if (value == null) {
                window.sessionStorage.removeItem(key);
            } else {
                window.sessionStorage.setItem(key, value);
            }
        } catch (error) {}
    }

    function readCookie(name) {
        const prefix = name + "=";
        const parts = document.cookie ? document.cookie.split(";") : [];
        for (let i = 0; i < parts.length; i += 1) {
            const part = parts[i].trim();
            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.slice(prefix.length));
            }
        }
        return "";
    }

    function getRole() {
        return readStore(ROLE_KEY);
    }

    function setRole(role) {
        writeStore(ROLE_KEY, role);
    }

    function setSessionFromLogin(payload) {
        if (!payload || ALLOWED_ROLES.indexOf(payload.role) === -1) {
            return;
        }
        setRole(payload.role);
        if (payload.user_id != null) {
            writeStore(USER_ID_KEY, String(payload.user_id));
        }
        if (payload.username) {
            writeStore(USERNAME_KEY, payload.username);
        }
        if (payload.display_name != null) {
            writeStore(DISPLAY_NAME_KEY, String(payload.display_name));
        }
        if (Array.isArray(payload.permissions)) {
            writeStore(PERMISSIONS_KEY, JSON.stringify(payload.permissions));
        }
    }

    function getUserId() {
        const value = readStore(USER_ID_KEY);
        return value ? parseInt(value, 10) : null;
    }

    function getUsername() {
        return readStore(USERNAME_KEY);
    }

    function getDisplayName() {
        return readStore(DISPLAY_NAME_KEY);
    }

    function getPermissions() {
        try {
            const value = JSON.parse(readStore(PERMISSIONS_KEY) || "[]");
            return Array.isArray(value) ? value : [];
        } catch (error) {
            return [];
        }
    }

    function can(permission) {
        return getPermissions().indexOf(permission) !== -1;
    }

    function clearRole() {
        [ROLE_KEY, USER_ID_KEY, USERNAME_KEY, DISPLAY_NAME_KEY, PERMISSIONS_KEY].forEach(function (key) {
            writeStore(key, null);
        });
    }

    function setNotice(code, message) {
        writeStore(NOTICE_KEY, JSON.stringify({ code: code, message: message }));
    }

    /** Returns (and forgets) the reason the last session ended, for the sign-in page. */
    function takeNotice() {
        const raw = readStore(NOTICE_KEY);
        writeStore(NOTICE_KEY, null);
        try {
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function endSession(code) {
        clearRole();
        setNotice(code, SESSION_END_MESSAGES[code] || SESSION_END_MESSAGES.not_authenticated);
        if (!isLoginPage) {
            window.location.href = LOGIN_URL;
        }
    }

    /*
     * Every same-origin fetch on these pages goes through here:
     *  - POST/PUT/PATCH/DELETE carry the CSRF token the server issued (X-CSRF-Token);
     *  - an expired token is refreshed and the request retried once;
     *  - a session the server ended sends the user to the sign-in page with the reason.
     */
    const nativeFetch = window.fetch.bind(window);

    function requestMethod(input, init) {
        if (init && init.method) {
            return String(init.method).toUpperCase();
        }
        if (input && typeof input === "object" && input.method) {
            return String(input.method).toUpperCase();
        }
        return "GET";
    }

    function requestUrl(input) {
        try {
            const raw = typeof input === "string" ? input : (input && input.url) || String(input);
            return new URL(raw, window.location.href);
        } catch (error) {
            return null;
        }
    }

    function refreshCsrfToken() {
        return nativeFetch(STATUS_URL, { credentials: "same-origin", cache: "no-store" })
            .then(function (response) { return response.text(); })
            .catch(function () { return ""; })
            .then(function () { return readCookie(CSRF_COOKIE); });
    }

    function withCsrf(input, init, token) {
        const options = Object.assign({}, init || {});
        const sourceHeaders = options.headers || (input && typeof input === "object" ? input.headers : undefined);
        const headers = new Headers(sourceHeaders || undefined);
        if (token) {
            headers.set("X-CSRF-Token", token);
        }
        options.headers = headers;
        if (!options.credentials) {
            options.credentials = "same-origin";
        }
        return options;
    }

    window.fetch = function (input, init) {
        const url = requestUrl(input);
        if (!url || url.origin !== window.location.origin) {
            return nativeFetch(input, init);
        }
        const unsafe = SAFE_METHODS.indexOf(requestMethod(input, init)) === -1;
        const existingToken = readCookie(CSRF_COOKIE);
        const tokenReady = !unsafe ? Promise.resolve("") : (existingToken ? Promise.resolve(existingToken) : refreshCsrfToken());

        return tokenReady
            .then(function (token) {
                return nativeFetch(input, unsafe ? withCsrf(input, init, token) : init);
            })
            .then(function (response) {
                const code = response.headers.get("X-Auth-Error") || "";
                if (unsafe && code === "csrf_failed") {
                    return refreshCsrfToken().then(function (token) {
                        return nativeFetch(input, withCsrf(input, init, token));
                    });
                }
                return response;
            })
            .then(function (response) {
                const code = response.headers.get("X-Auth-Error") || "";
                if (!isLoginPage && SESSION_END_MESSAGES[code] && (response.status === 401 || response.status === 429)) {
                    endSession(code);
                }
                return response;
            });
    };

    function csrfToken() {
        return readCookie(CSRF_COOKIE);
    }

    function logout() {
        return fetch(new URL("logout.php", BASE).href, {
            method: "POST",
            credentials: "same-origin",
        })
            .catch(function () {
                return null;
            })
            .then(function () {
                clearRole();
                setNotice("signed_out", "You signed out.");
            });
    }

    /** Confirms with the server that this browser is signed in with one of the roles. */
    function verifyServerSession(allowed) {
        nativeFetch(STATUS_URL, { credentials: "same-origin", cache: "no-store" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.authenticated) {
                    endSession((data && data.reason) || "not_authenticated");
                    return;
                }
                if (allowed.indexOf(data.role) === -1) {
                    clearRole();
                    window.location.href = LOGIN_URL;
                    return;
                }
                setSessionFromLogin(data);
                document.dispatchEvent(new CustomEvent("auth:session", { detail: data }));
            })
            .catch(function () {});
    }

    /**
     * @param {string[]} allowed
     * @returns {boolean} false if redirecting to the sign-in page
     */
    function requireRole(allowed) {
        const role = getRole();
        if (!role || allowed.indexOf(role) === -1) {
            window.location.href = LOGIN_URL;
            return false;
        }
        verifyServerSession(allowed);
        return true;
    }

    window.PhoneTrackerAuth = {
        getRole,
        setRole,
        setSessionFromLogin,
        getUserId,
        getUsername,
        getDisplayName,
        getPermissions,
        can,
        clearRole,
        logout,
        requireRole,
        takeNotice,
        csrfToken,
        baseUrl: BASE.href,
    };
})();
