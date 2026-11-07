/*
 * Request a teacher account (SCHOOL_ACCOUNTS.md): the teacher's details, a code sent to their
 * @isatu.edu.ph email, then the request waits for their department's Dean's Office.
 */
(function () {
    "use strict";

    const UI = window.PhoneTrackerAuthUI;
    const $ = function (id) { return document.getElementById(id); };
    const state = { departments: [], requestId: 0, email: "", resendAt: 0, resendTimer: 0 };

    function show(panelId) {
        ["requestPanel", "closedPanel", "codePanel", "sentPanel"].forEach(function (id) {
            $(id).hidden = id !== panelId;
        });
        const title = $(panelId).querySelector(".auth-panel-title");
        if (title) {
            title.focus();
        }
    }

    function message(element, text, success) {
        element.textContent = text || "";
        element.hidden = !text;
        if (success) {
            element.dataset.state = "success";
        } else {
            delete element.dataset.state;
        }
    }

    async function call(payload) {
        let response;
        try {
            response = await fetch("teacher_request.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });
        } catch (error) {
            return { success: false, message: "Can't reach the server. Check your connection and try again." };
        }
        try {
            return await response.json();
        } catch (error) {
            return { success: false, message: "Something went wrong on the server. Please try again." };
        }
    }

    function option(value, text) {
        const item = document.createElement("option");
        item.value = value;
        item.textContent = text;
        return item;
    }

    function fillCourses() {
        const department = state.departments.find(function (item) { return item.code === $("reqDepartment").value; });
        const select = $("reqCourse");
        select.replaceChildren(option("", ""));
        select.firstChild.disabled = true;
        select.firstChild.selected = true;
        (department ? department.courses : []).forEach(function (course) { select.appendChild(option(course.code, course.name)); });
        select.disabled = !department;
        if (department && department.courses.length === 1) {
            select.value = department.courses[0].code;
        }
    }

    async function load() {
        try {
            const response = await fetch("teacher_request.php", { credentials: "same-origin", cache: "no-store" });
            const data = await response.json();
            if (!data || !data.success) {
                message($("requestMessage"), (data && data.message) || "Could not load the form. Please try again.");
                return;
            }
            data.titles.forEach(function (title) { $("reqTitle").appendChild(option(title, title)); });
            state.departments = data.departments || [];
            if (!state.departments.length) {
                show("closedPanel");
                return;
            }
            state.departments.forEach(function (department) {
                $("reqDepartment").appendChild(option(department.code, department.code + ", " + department.name));
            });
            if (state.departments.length === 1) {
                $("reqDepartment").value = state.departments[0].code;
                fillCourses();
            }
        } catch (error) {
            message($("requestMessage"), "Can't reach the server. Check your connection and try again.");
        }
    }

    const password = UI.bindPasswordFeedback({
        input: $("reqPassword"),
        confirm: $("reqConfirm"),
        meter: $("reqStrength"),
        label: $("reqStrengthLabel"),
        rules: $("reqRules"),
        getPolicy: function () { return null; },
        getIdentity: function () {
            const email = $("reqEmail").value.trim();
            return {
                username: email.split("@")[0],
                email: email,
                display_name: $("reqFirst").value + " " + $("reqLast").value,
            };
        },
    });
    UI.bindPasswordToggles(document);

    function emailProblem(email) {
        const domain = email.toLowerCase().split("@")[1] || "";
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
            return "Enter your school email.";
        }
        if (domain === "students.isatu.edu.ph") {
            return "This is a student email. Students sign up in the ISATU app.";
        }
        if (domain !== "isatu.edu.ph") {
            return "Use your @isatu.edu.ph email.";
        }
        return "";
    }

    $("reqEmail").addEventListener("blur", function () {
        const email = $("reqEmail").value.trim();
        const problem = email ? emailProblem(email) : "";
        $("reqEmailHint").textContent = problem || "Only @isatu.edu.ph emails.";
        $("reqEmailHint").classList.toggle("is-error", problem !== "");
    });
    $("reqDepartment").addEventListener("change", fillCourses);

    $("requestForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        const email = $("reqEmail").value.trim();
        const problem = emailProblem(email)
            || (!$("reqTitle").value ? "Choose your title." : "")
            || (!$("reqFirst").value.trim() || !$("reqLast").value.trim() ? "Enter your first and last name." : "")
            || (!$("reqDepartment").value ? "Choose your department." : "")
            || (!$("reqCourse").value ? "Choose your course." : "")
            || password.firstProblem();
        if (problem) {
            message($("requestMessage"), problem);
            return;
        }
        message($("requestMessage"), "");
        const button = $("requestBtn");
        button.disabled = true;
        button.textContent = "Sending…";
        try {
            const data = await call({
                action: "request",
                email: email,
                name_title: $("reqTitle").value,
                first_name: $("reqFirst").value.trim(),
                last_name: $("reqLast").value.trim(),
                department_code: $("reqDepartment").value,
                course_code: $("reqCourse").value,
                password: $("reqPassword").value,
            });
            if (!data.success) {
                const fieldMessage = data.errors ? Object.values(data.errors)[0] : "";
                message($("requestMessage"), fieldMessage || data.message || "Could not send the request.");
                return;
            }
            codeSent(data);
            show("codePanel");
            code.focus();
        } finally {
            button.disabled = false;
            button.textContent = "Send code";
        }
    });

    const code = UI.bindOtpInput($("codeInput"), document.querySelector("#codeGroup .auth-otp-slots"), function () {
        $("codeForm").requestSubmit();
    });

    function codeSent(data) {
        state.requestId = data.request_id;
        state.email = data.email;
        $("codeEmail").textContent = data.email;
        state.resendAt = Date.now() + (data.resend_after_seconds || 60) * 1000;
        tickResend();
        code.clear();
        message($("codeMessage"), "");
    }

    function tickResend() {
        window.clearTimeout(state.resendTimer);
        const left = Math.ceil((state.resendAt - Date.now()) / 1000);
        const button = $("resendBtn");
        button.disabled = left > 0;
        button.textContent = left > 0 ? "Send the code again in " + left + "s" : "Send the code again";
        if (left > 0) {
            state.resendTimer = window.setTimeout(tickResend, 1000);
        }
    }

    $("codeForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        const value = code.value();
        if (!/^\d{6}$/.test(value)) {
            message($("codeMessage"), "Enter the 6 digits from the email.");
            return;
        }
        const button = $("codeBtn");
        button.disabled = true;
        try {
            const data = await call({ action: "confirm", request_id: state.requestId, code: value });
            if (!data.success) {
                code.invalid();
                message($("codeMessage"), data.message || "That code didn't work.");
                return;
            }
            $("sentText").textContent = "The " + data.approver_name + " will check that you teach there. We'll email " + state.email + " when they answer.";
            const summary = $("sentSummary");
            summary.replaceChildren();
            [["Name", data.name], ["Department", data.department], ["Course", data.course]].forEach(function (pair) {
                const row = document.createElement("div");
                const term = document.createElement("dt");
                term.textContent = pair[0];
                const value = document.createElement("dd");
                value.textContent = pair[1];
                row.append(term, value);
                summary.appendChild(row);
            });
            show("sentPanel");
        } finally {
            button.disabled = false;
        }
    });

    $("resendBtn").addEventListener("click", async function () {
        const data = await call({ action: "resend", request_id: state.requestId });
        if (!data.success) {
            message($("codeMessage"), data.message || "Could not send a new code.");
            if (data.retry_after) {
                state.resendAt = Date.now() + data.retry_after * 1000;
                tickResend();
            }
            return;
        }
        codeSent(data);
        message($("codeMessage"), "We sent a new code. The old one no longer works.", true);
    });

    $("changeBtn").addEventListener("click", function () {
        window.clearTimeout(state.resendTimer);
        show("requestPanel");
    });

    load();
})();
