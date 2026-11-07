/*
 * The system's own confirmation and message dialogs, used instead of the browser's confirm() and
 * alert(). Those can't be styled, open at the top of the window under the site's address, and only
 * offer "OK". These open in the middle of the screen, look like the other dialogs (style.css,
 * "Confirmation dialogs"), and name the action on their button.
 *
 *   if (!(await AppDialog.confirm({ title: "Remove Ana?", message: "…", confirmLabel: "Remove account", danger: true }))) {
 *       return;
 *   }
 *   await AppDialog.alert({ title: "Couldn't remove Ana", message: data.message });
 *
 * Options: title, message (a blank line starts a new paragraph), confirmLabel, cancelLabel, danger
 * (a red button, and Cancel gets the focus), eyebrow (the small label above the title).
 * confirm() resolves true only for the action button; the X, Escape, Cancel and a click outside all
 * mean no. One dialog shows at a time; a second waits for the first to close.
 */
(function () {
    "use strict";

    let dialog = null;
    let queue = Promise.resolve();

    function build() {
        const element = document.createElement("dialog");
        element.className = "admin-dialog app-dialog";
        element.setAttribute("aria-labelledby", "appDialogTitle");
        element.setAttribute("aria-describedby", "appDialogMessage");
        element.innerHTML =
            '<form class="app-dialog-form" method="dialog">' +
            '<header class="admin-dialog-header">' +
            '<div><p class="admin-eyebrow" id="appDialogEyebrow" hidden></p><h2 id="appDialogTitle"></h2></div>' +
            '<button class="admin-dialog-close" type="button" data-app-dialog="cancel" aria-label="Close">×</button>' +
            "</header>" +
            '<div class="app-dialog-message" id="appDialogMessage"></div>' +
            '<div class="admin-dialog-actions app-dialog-actions">' +
            '<button class="admin-cancel-button" type="button" data-app-dialog="cancel" id="appDialogCancel"></button>' +
            '<button class="admin-primary-button" type="button" data-app-dialog="confirm" id="appDialogConfirm"></button>' +
            "</div>" +
            "</form>";
        document.body.appendChild(element);
        return element;
    }

    function fill(kind, options) {
        const eyebrow = dialog.querySelector("#appDialogEyebrow");
        eyebrow.textContent = options.eyebrow || "";
        eyebrow.hidden = !options.eyebrow;
        dialog.querySelector("#appDialogTitle").textContent = options.title || "";
        const message = dialog.querySelector("#appDialogMessage");
        message.replaceChildren();
        String(options.message || "").split(/\n\s*\n/).forEach(function (text) {
            if (text.trim()) {
                const paragraph = document.createElement("p");
                paragraph.textContent = text.trim();
                message.appendChild(paragraph);
            }
        });
        message.hidden = !message.childNodes.length;
        const danger = Boolean(options.danger);
        const confirmButton = dialog.querySelector("#appDialogConfirm");
        confirmButton.textContent = options.confirmLabel || (kind === "alert" ? "OK" : "Continue");
        confirmButton.className = danger ? "app-dialog-danger" : "admin-primary-button";
        const cancelButton = dialog.querySelector("#appDialogCancel");
        cancelButton.textContent = options.cancelLabel || "Cancel";
        cancelButton.hidden = kind === "alert";
        dialog.classList.toggle("is-danger", danger);
        // A risky action starts on the safe button, so a quick Enter doesn't do it.
        return danger && kind !== "alert" ? cancelButton : confirmButton;
    }

    function open(kind, options) {
        dialog = dialog || build();
        const focusTarget = fill(kind, options || {});
        return new Promise(function (resolve) {
            let answer = false;
            function onClick(event) {
                const button = event.target.closest("[data-app-dialog]");
                if (button) {
                    answer = button.dataset.appDialog === "confirm";
                    dialog.close();
                    return;
                }
                // A click on the dimmed page around the dialog.
                if (event.target === dialog) {
                    const box = dialog.getBoundingClientRect();
                    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
                        dialog.close();
                    }
                }
            }
            // However it closes (a button, Escape, a click outside), the answer is settled here.
            function onClose() {
                dialog.removeEventListener("click", onClick);
                dialog.removeEventListener("close", onClose);
                resolve(answer);
            }
            dialog.addEventListener("click", onClick);
            dialog.addEventListener("close", onClose);
            dialog.showModal();
            focusTarget.focus();
        });
    }

    function enqueue(kind, options) {
        const shown = queue.then(function () {
            return open(kind, options);
        });
        queue = shown.catch(function () {});
        return shown;
    }

    window.AppDialog = {
        /** Resolves true when the person chooses the action, false otherwise. */
        confirm: function (options) {
            return enqueue("confirm", options);
        },
        /** A message with one button. Resolves when it is closed. */
        alert: function (options) {
            return enqueue("alert", options).then(function () {});
        },
    };
})();
