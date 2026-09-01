import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { PushConfig } from '@/types';

/** Kept in step with the version the service worker pulls in. */
const SDK_VERSION = '12.1.0';

declare global {
    interface Window {
        firebase?: any;
    }
}

/**
 * Turning browser push on and off.
 *
 * Firebase's SDK is fetched from Google's CDN on demand rather than bundled.
 * The service worker has to load it that way regardless — a file in `public/`
 * cannot import from the app bundle — so taking the same route here keeps one
 * copy of the SDK on the page and nothing at all for the people who never open
 * this screen.
 */
export function usePush(config: PushConfig | null) {
    const supported =
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'Notification' in window &&
        'PushManager' in window;

    const busy = ref(false);
    const error = ref<string | null>(null);
    const permission = ref<NotificationPermission>(
        supported ? Notification.permission : 'denied',
    );

    function script(src: string): Promise<void> {
        const existing = document.querySelector(`script[src="${src}"]`);

        if (existing) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const tag = document.createElement('script');

            tag.src = src;
            tag.onload = () => resolve();
            tag.onerror = () =>
                reject(new Error('Could not load Firebase from the network.'));

            document.head.appendChild(tag);
        });
    }

    async function messaging(): Promise<any> {
        const base = `https://www.gstatic.com/firebasejs/${SDK_VERSION}`;

        await script(`${base}/firebase-app-compat.js`);
        await script(`${base}/firebase-messaging-compat.js`);

        const firebase = window.firebase;

        // Re-registering the same config throws, so an app already on the page
        // is reused.
        if (!firebase.apps.length) {
            firebase.initializeApp(config);
        }

        return firebase.messaging();
    }

    /**
     * The service worker takes its config on the query string, since a file in
     * `public/` has no way to read Laravel's.
     */
    function registration(): Promise<ServiceWorkerRegistration> {
        const query = encodeURIComponent(JSON.stringify(config));

        return navigator.serviceWorker.register(
            `/firebase-messaging-sw.js?config=${query}`,
            { scope: '/' },
        );
    }

    /**
     * Ask the browser, then hand the registration token to the server. The
     * prompt is the browser's own, and a refusal is final until the person
     * clears it in their site settings.
     */
    async function enable(): Promise<void> {
        if (!supported || !config || busy.value) {
            return;
        }

        busy.value = true;
        error.value = null;

        try {
            permission.value = await Notification.requestPermission();

            if (permission.value !== 'granted') {
                error.value =
                    'Your browser is blocking notifications for this site. Allow them in the address bar to turn push on.';

                return;
            }

            const token = await (
                await messaging()
            ).getToken({
                vapidKey: config.vapidKey,
                serviceWorkerRegistration: await registration(),
            });

            if (!token) {
                error.value =
                    'Firebase did not return a token for this browser.';

                return;
            }

            router.post('/push-tokens', { token }, { preserveScroll: true });
        } catch (thrown) {
            error.value =
                thrown instanceof Error
                    ? thrown.message
                    : 'Push could not be switched on.';
        } finally {
            busy.value = false;
        }
    }

    /**
     * Drop every browser this person registered. The token is deleted at
     * Firebase too where we can, so a stale one cannot be revived.
     */
    async function disable(): Promise<void> {
        if (busy.value) {
            return;
        }

        busy.value = true;
        error.value = null;

        try {
            if (supported && config) {
                await (await messaging()).deleteToken();
            }
        } catch {
            // Firebase not letting go of the token does not stop us letting go
            // of it: what matters is that the server stops sending.
        } finally {
            router.delete('/push-tokens', { preserveScroll: true });
            busy.value = false;
        }
    }

    return { supported, busy, error, permission, enable, disable };
}
