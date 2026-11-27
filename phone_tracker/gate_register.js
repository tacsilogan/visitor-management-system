/*
 * "Register at the gate" (GATE_REGISTRATION.md): Security's form for a visitor who can't use the
 * ISATU Visits app or doesn't want location tracking. Used on the Security dashboard and on the
 * Scan visitor pass page. The guard checks the visitor's ID, fills the form and reads the notice
 * to them; the visitor is then checked in at once and their location is not tracked.
 *
 * The people with the visitor (GROUP_VISITS.md) are added one by one: each person's name as on
 * their ID, whether they are 18 or older, and for adults the ID they showed.
 *
 *   GateRegister.open({ onRegistered: function (visit) { … } });
 *
 * visit: appointment_id, registration_code, visitor_full_name, office_code, office_label, checked_in_at,
 * people_count.
 * Needs dialogs.js and auth.js (which adds the sign-in safety token to the request).
 */
(function () {
    "use strict";

    const MAX_PEOPLE = 10;
    let dialog = null;
    let offices = [];
    let idTypes = [];
    let people = [];
    let onRegistered = null;
    let busy = false;

    function esc(text) {
        return String(text == null ? "" : text).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    const FIELDS = {
        full_name: "gateFullName",
        id_type: "gateIdType",
        contact_number: "gateContact",
        office_code: "gateOffice",
        purpose: "gatePurpose",
        concern: "gateConcern",
        consent: "gateConsent",
    };

    function byId(id) {
        return document.getElementById(id);
    }

    function field(label, control, wide) {
        return '<label class="gate-field' + (wide ? " admin-office-field" : "") + '">' +
            "<span>" + label + "</span>" + control +
            '<small class="gate-field-error" hidden></small></label>';
    }

    function build() {
        const element = document.createElement("dialog");
        element.className = "admin-dialog gate-dialog";
        element.setAttribute("aria-labelledby", "gateDialogTitle");
        element.innerHTML =
            '<form class="gate-form" id="gateForm" novalidate>' +
            '<header class="admin-dialog-header">' +
            '<div><p class="admin-eyebrow">Visitor without the app</p><h2 id="gateDialogTitle">Register at the gate</h2></div>' +
            '<button class="admin-dialog-close" type="button" data-gate-close aria-label="Close">×</button>' +
            "</header>" +
            '<p class="gate-help">For visitors who can\'t use the ISATU Visits app or don\'t want location tracking. ' +
            "Check their ID first. They are checked in right away, and their location is not tracked.</p>" +
            '<p class="gate-section">Visitor</p>' +
            '<div class="admin-form-grid">' +
            field('Full name <span class="gate-req" aria-hidden="true">*</span>',
                '<input type="text" id="gateFullName" maxlength="150" autocomplete="off" spellcheck="false" required>', true) +
            field('ID shown <span class="gate-req" aria-hidden="true">*</span>', '<select id="gateIdType" required></select>') +
            field("Contact number (optional)", '<input type="tel" id="gateContact" maxlength="32" inputmode="tel" autocomplete="off">') +
            "</div>" +
            '<p class="gate-section">People with them</p>' +
            '<div class="gate-people" id="gatePeople"></div>' +
            '<p class="gate-section">Visit</p>' +
            '<div class="admin-form-grid">' +
            field('Office <span class="gate-req" aria-hidden="true">*</span>', '<select id="gateOffice" required></select>') +
            field('Purpose <span class="gate-req" aria-hidden="true">*</span>', '<select id="gatePurpose" required></select>') +
            field("Concern (optional)", '<input type="text" id="gateConcern" maxlength="150" autocomplete="off">', true) +
            "</div>" +
            '<p class="gate-office-state" id="gateOfficeState" role="status" hidden></p>' +
            '<div class="gate-consent">' +
            "<p>Read to the visitor</p>" +
            '<blockquote id="gateNoticeText"></blockquote>' +
            '<label class="gate-consent-check"><input type="checkbox" id="gateConsent"> The visitor agreed</label>' +
            '<small class="gate-field-error" hidden></small>' +
            "</div>" +
            '<p class="admin-message is-error gate-form-error" id="gateFormError" role="alert" hidden></p>' +
            '<div class="admin-dialog-actions">' +
            '<button class="admin-cancel-button" type="button" data-gate-close>Cancel</button>' +
            '<button class="admin-primary-button" type="submit" id="gateSubmitBtn">Register and check in</button>' +
            "</div>" +
            "</form>";
        document.body.appendChild(element);

        element.querySelectorAll("[data-gate-close]").forEach(function (button) {
            button.addEventListener("click", function () {
                element.close();
            });
        });
        element.addEventListener("click", function (event) {
            // A click on the dimmed page around the form closes it, as in the other dialogs.
            if (event.target === element && !busy) {
                const box = element.getBoundingClientRect();
                if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
                    element.close();
                }
            }
        });
        element.querySelector("#gateForm").addEventListener("submit", submit);
        element.querySelector("#gateOffice").addEventListener("change", showOfficeState);
        const box = element.querySelector("#gatePeople");
        box.addEventListener("click", function (event) {
            const remove = event.target.closest("[data-gate-remove]");
            if (remove) {
                people.splice(Number(remove.dataset.gateRemove), 1);
                renderPeople();
                return;
            }
            if (event.target.closest("[data-gate-add]")) {
                people.push({ full_name: "", is_adult: true, id_type: "", errors: {} });
                renderPeople();
                const names = box.querySelectorAll("[data-gate-name]");
                names[names.length - 1].focus();
            }
        });
        box.addEventListener("change", function (event) {
            const target = event.target;
            if (target.matches("[data-gate-age]")) {
                const person = people[Number(target.dataset.gateAge)];
                person.is_adult = target.value === "adult";
                person.errors = {};
                renderPeople();
            } else if (target.matches("[data-gate-id]")) {
                const person = people[Number(target.dataset.gateId)];
                person.id_type = target.value;
                delete person.errors.id_type;
                renderPeople();
            }
        });
        box.addEventListener("input", function (event) {
            if (event.target.matches("[data-gate-name]")) {
                people[Number(event.target.dataset.gateName)].full_name = event.target.value;
            }
        });
        Object.keys(FIELDS).forEach(function (name) {
            const control = element.querySelector("#" + FIELDS[name]);
            control.addEventListener(control.type === "checkbox" || control.tagName === "SELECT" ? "change" : "input", function () {
                setFieldError(name, "");
            });
        });
        return element;
    }

    /** The people with the visitor: one block each, then "Add a person". */
    function renderPeople() {
        const box = byId("gatePeople");
        const error = function (message) {
            return message ? '<small class="gate-field-error">' + esc(message) + "</small>" : "";
        };
        box.innerHTML = people.map(function (person, index) {
            const age = "gateAge" + index;
            const errors = person.errors || {};
            return '<div class="gate-person">' +
                '<div class="gate-person-head"><strong>Person ' + (index + 1) + "</strong>" +
                '<button class="gate-person-x" type="button" data-gate-remove="' + index + '">Remove</button></div>' +
                '<div class="admin-form-grid">' +
                '<label class="gate-field admin-office-field"><span>Full name, as on their ID <span class="gate-req" aria-hidden="true">*</span></span>' +
                '<input type="text" maxlength="150" autocomplete="off" spellcheck="false" data-gate-name="' + index + '" value="' + esc(person.full_name) + '"' +
                (errors.full_name ? ' aria-invalid="true"' : "") + ">" + error(errors.full_name) + "</label>" +
                '<div class="gate-field"><span class="gate-age-label">Age</span><div class="gp-age" role="radiogroup" aria-label="Age of person ' + (index + 1) + '">' +
                '<label class="idc-chip"><input type="radio" name="' + age + '" value="adult" data-gate-age="' + index + '"' + (person.is_adult ? " checked" : "") + "><span>18 or older</span></label>" +
                '<label class="idc-chip"><input type="radio" name="' + age + '" value="child" data-gate-age="' + index + '"' + (person.is_adult ? "" : " checked") + "><span>Under 18</span></label>" +
                "</div></div>" +
                (person.is_adult
                    ? '<label class="gate-field"><span>ID shown <span class="gate-req" aria-hidden="true">*</span></span>' +
                      '<select data-gate-id="' + index + '"' + (errors.id_type ? ' aria-invalid="true"' : "") + '><option value="">Choose the ID</option>' +
                      idTypes.map(function (type) {
                          return "<option" + (person.id_type === type ? " selected" : "") + ">" + esc(type) + "</option>";
                      }).join("") + "</select>" + error(errors.id_type) + "</label>"
                    : '<div class="gate-field"><span class="gate-age-label">ID shown</span><p class="gate-nid">Not needed under 18</p></div>') +
                "</div></div>";
        }).join("") +
            (people.length < MAX_PEOPLE ? '<button class="gp-add-btn" type="button" data-gate-add>' +
                '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add a person</button>' : "") +
            '<p class="gate-people-note">' + (people.length
                ? "Family or friends who came with them, up to " + MAX_PEOPLE + ". Check the ID of each adult: the name must match."
                : "Anyone who came with the visitor? Add each one with their name as on their ID.") + "</p>";
    }

    /** The people's mistakes, from this page or from the server ("people.0.id_type": "…"). */
    function showPeopleErrors(errors) {
        let any = false;
        people.forEach(function (person) {
            person.errors = {};
        });
        Object.keys(errors).forEach(function (key) {
            const match = /^people\.(\d+)\.(full_name|id_type)$/.exec(key);
            if (match && people[Number(match[1])]) {
                people[Number(match[1])].errors[match[2]] = errors[key];
                any = true;
            }
        });
        renderPeople();
        return any;
    }

    function setFieldError(name, message) {
        const control = byId(FIELDS[name]);
        if (!control) {
            return;
        }
        const holder = name === "consent" ? control.closest(".gate-consent") : control.closest(".gate-field");
        const note = holder.querySelector(".gate-field-error");
        note.textContent = message || "";
        note.hidden = !message;
        control.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function showFormError(message) {
        const box = byId("gateFormError");
        box.textContent = message || "";
        box.hidden = !message;
    }

    function fillSelect(select, values, placeholder) {
        select.replaceChildren(new Option(placeholder, ""));
        values.forEach(function (value) {
            if (typeof value === "string") {
                select.appendChild(new Option(value, value));
            } else {
                select.appendChild(new Option(value.label, value.value));
            }
        });
    }

    function selectedOffice() {
        const code = byId("gateOffice").value;
        return offices.find(function (office) {
            return office.code === code;
        }) || null;
    }

    function showOfficeState() {
        const office = selectedOffice();
        const box = byId("gateOfficeState");
        box.hidden = !office;
        if (office) {
            box.textContent = office.message;
            box.classList.toggle("is-closed", !office.can_register);
        }
        byId("gateSubmitBtn").disabled = Boolean(office && !office.can_register);
    }

    async function loadOptions() {
        const response = await fetch("gate_options.php", { credentials: "same-origin", cache: "no-store" });
        if (response.status === 401) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        const data = await response.json();
        if (!data || !data.success) {
            throw new Error((data && data.message) || "Could not load the offices.");
        }
        if (!data.ready) {
            throw new Error("The database needs an update before visitors can be registered at the gate.");
        }
        return data;
    }

    function reset() {
        byId("gateForm").reset();
        Object.keys(FIELDS).forEach(function (name) {
            setFieldError(name, "");
        });
        showFormError("");
        byId("gateOfficeState").hidden = true;
        byId("gateSubmitBtn").disabled = false;
        byId("gateSubmitBtn").textContent = "Register and check in";
        people = [];
        renderPeople();
    }

    async function open(options) {
        onRegistered = options && typeof options.onRegistered === "function" ? options.onRegistered : null;
        dialog = dialog || build();
        reset();
        byId("gateSubmitBtn").disabled = true;
        byId("gateNoticeText").textContent = "Loading…";
        dialog.showModal();
        byId("gateFullName").focus();
        try {
            const data = await loadOptions();
            offices = Array.isArray(data.offices) ? data.offices : [];
            idTypes = Array.isArray(data.id_types) ? data.id_types : [];
            renderPeople();
            fillSelect(byId("gateIdType"), data.id_types || [], "Choose the ID");
            fillSelect(byId("gatePurpose"), data.purposes || [], "Choose the purpose");
            fillSelect(byId("gateOffice"), offices.map(function (office) {
                return { value: office.code, label: office.can_register ? office.name : office.name + " (not taking visitors now)" };
            }), offices.length ? "Choose the office" : "No office has Office Personnel");
            byId("gateNoticeText").textContent = "“" + ((data.notice && data.notice.text) || "") + "”";
            byId("gateSubmitBtn").disabled = offices.length === 0;
            if (!offices.length) {
                showFormError("No office has Office Personnel yet, so no visitor can be registered. Ask the Admin to add them.");
            }
        } catch (error) {
            if (error.message !== "Not authenticated") {
                byId("gateNoticeText").textContent = "";
                showFormError(error instanceof TypeError ? "Couldn't reach the server. Check the connection and try again." : error.message);
            }
        }
    }

    function collect() {
        return {
            full_name: byId("gateFullName").value.trim(),
            id_type: byId("gateIdType").value,
            contact_number: byId("gateContact").value.trim(),
            office_code: byId("gateOffice").value,
            purpose: byId("gatePurpose").value,
            concern: byId("gateConcern").value.trim(),
            consent: byId("gateConsent").checked,
            people: people.map(function (person) {
                return { full_name: person.full_name.trim(), is_adult: person.is_adult, id_type: person.is_adult ? person.id_type : "" };
            }),
        };
    }

    /** The same checks the server makes, so most mistakes show before sending. */
    function localErrors(values) {
        const errors = {};
        if (values.full_name.length < 2) {
            errors.full_name = "Enter the visitor's full name as it appears on their ID.";
        }
        if (!values.id_type) {
            errors.id_type = "Choose the type of ID the visitor showed.";
        }
        if (!values.office_code) {
            errors.office_code = "Choose the office they are visiting.";
        }
        if (!values.purpose) {
            errors.purpose = "Choose the purpose of the visit.";
        }
        if (!values.consent) {
            errors.consent = "Read the notice to the visitor, then tick that they agreed.";
        }
        values.people.forEach(function (person, index) {
            if (person.full_name.length < 2) {
                errors["people." + index + ".full_name"] = "Person " + (index + 1) + ": enter their full name as it appears on their ID.";
            }
            if (person.is_adult && !person.id_type) {
                errors["people." + index + ".id_type"] = "Person " + (index + 1) + ": choose the type of ID they showed.";
            }
        });
        return errors;
    }

    function showErrors(errors) {
        let first = null;
        const peopleWrong = showPeopleErrors(errors);
        Object.keys(errors).forEach(function (name) {
            if (FIELDS[name]) {
                setFieldError(name, errors[name]);
                first = first || byId(FIELDS[name]);
            }
        });
        if (!first && peopleWrong) {
            first = byId("gatePeople").querySelector('[aria-invalid="true"]');
        }
        if (first) {
            first.focus();
        }
    }

    async function submit(event) {
        event.preventDefault();
        if (busy) {
            return;
        }
        showFormError("");
        const values = collect();
        const errors = localErrors(values);
        if (Object.keys(errors).length) {
            showErrors(errors);
            return;
        }
        const button = byId("gateSubmitBtn");
        busy = true;
        button.disabled = true;
        button.textContent = "Registering…";
        try {
            const response = await fetch("gate_register.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(values),
            });
            if (response.status === 401) {
                window.location.href = "login.html";
                return;
            }
            const data = await response.json();
            if (!data || !data.success) {
                if (data && data.errors && Object.keys(data.errors).length) {
                    showErrors(data.errors);
                }
                showFormError((data && data.message) || "Could not register this visitor.");
                // The office may have closed while the form was open: show where it stands now.
                if (data && !data.errors) {
                    const fresh = await loadOptions().catch(function () { return null; });
                    if (fresh && Array.isArray(fresh.offices)) {
                        offices = fresh.offices;
                        showOfficeState();
                    }
                }
                return;
            }
            dialog.close();
            if (onRegistered) {
                onRegistered(data.visit, data.message);
            }
        } catch (error) {
            showFormError(error instanceof TypeError
                ? "Couldn't reach the server. Check the connection and try again."
                : (error.message || "Could not register this visitor."));
        } finally {
            busy = false;
            if (dialog.open) {
                button.textContent = "Register and check in";
                const office = selectedOffice();
                button.disabled = Boolean(office && !office.can_register);
            }
        }
    }

    window.GateRegister = { open: open };
})();
