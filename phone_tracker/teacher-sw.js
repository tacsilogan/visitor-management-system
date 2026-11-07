/*
 * Phone alerts for the Teacher dashboard. The push itself carries no message: it only wakes this
 * file, which asks the server what's new using the teacher's own session (web_push_service.php).
 * If the teacher has been signed out, the alert just says someone needs an answer.
 */
self.addEventListener("push", function (event) {
    event.waitUntil(showLatest());
});

async function showLatest() {
    let title = "ISATU Teacher Dashboard";
    let body = "A visitor needs your answer.";
    try {
        const response = await fetch("teacher_push.php?latest=1", { credentials: "include", cache: "no-store" });
        const data = await response.json();
        if (data && data.success && data.notification) {
            title = data.notification.title;
            body = data.notification.message;
        }
    } catch (error) {
        // Shown with the plain words above.
    }
    await self.registration.showNotification(title, {
        body: body,
        icon: "assets/images/isatu-seal-192.png",
        badge: "assets/images/isatu-seal-192.png",
        tag: "teacher-alert",
        renotify: true,
        data: { url: "teacher.html" },
    });
}

self.addEventListener("notificationclick", function (event) {
    event.notification.close();
    event.waitUntil(openDashboard(event.notification.data && event.notification.data.url));
});

async function openDashboard(url) {
    const windows = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const client of windows) {
        if (client.url.indexOf("teacher.html") !== -1 && "focus" in client) {
            return client.focus();
        }
    }
    return self.clients.openWindow(url || "teacher.html");
}
