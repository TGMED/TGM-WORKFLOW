/**
 * Firebase's background message handler.
 *
 * This has to live at the origin root and be plain JavaScript, so it cannot
 * read Laravel's config or share the app's bundle. The page passes the public
 * Firebase keys on the query string when it registers this worker, and the
 * SDK is pulled from Google's CDN in the compat build, which is the only one
 * that works under importScripts.
 */

const params = new URL(self.location.href).searchParams;
const config = JSON.parse(params.get('config') ?? 'null');

if (config) {
    importScripts(
        'https://www.gstatic.com/firebasejs/12.1.0/firebase-app-compat.js',
    );
    importScripts(
        'https://www.gstatic.com/firebasejs/12.1.0/firebase-messaging-compat.js',
    );

    firebase.initializeApp(config);

    firebase.messaging().onBackgroundMessage((payload) => {
        const notification = payload.notification ?? {};

        self.registration.showNotification(
            notification.title ?? 'Notification',
            {
                body: notification.body ?? '',
                icon: '/apple-touch-icon.png',
                badge: '/favicon-32.png',
                // Read back in the click handler to know where to send them.
                data: payload.data ?? {},
            },
        );
    });
}

// Clicking the notification should land on the page it is about, reusing a
// tab that already has the app open rather than piling up new ones.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = event.notification.data?.url;

    if (!target) {
        return;
    }

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                for (const client of clients) {
                    if (client.url === target && 'focus' in client) {
                        return client.focus();
                    }
                }

                return self.clients.openWindow(target);
            }),
    );
});
