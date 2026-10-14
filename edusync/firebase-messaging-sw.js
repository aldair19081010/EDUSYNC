/* EduSync Web Push (FCM data-only) - scope /edusync/. 
 * Se mantiene independiente de la app Flutter y del envío Push a Android.
 * No realiza peticiones de API ni almacena notas o información escolar.
 */
'use strict';

self.addEventListener('install', function(event) { event.waitUntil(self.skipWaiting()); });
self.addEventListener('activate', function(event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function(event) {
    if (!event.data) return;
    let raw;
    try { raw = event.data.json(); } catch (_) { return; }
    const data = raw && typeof raw.data === 'object' && raw.data !== null
        ? raw.data : raw;
    if (!data || typeof data.title !== 'string' || !data.title.trim()) return;
    const title = data.title.slice(0, 150);
    const body = String(data.body || '').slice(0, 450);
    const tag = String(data.tag || 'edusync-web').slice(0, 100);
    const icon = new URL('assets/uploads/logo.jpg', self.registration.scope).href;
    const options = {
        body: body,
        icon: icon,
        badge: icon,
        tag: tag,
        renotify: true,
        data: {url: String(data.url || 'index.php?page=notifications')},
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const scope = new URL(self.registration.scope);
    const candidate = (event.notification.data || {}).url || 'index.php?page=notifications';
    let url;
    try { url = new URL(candidate, scope); }
    catch (_) { url = new URL('index.php?page=notifications', scope); }
    // Sólo se permite navegar a una página interna de EduSync, nunca a otro sitio.
    if (url.origin !== scope.origin || !url.pathname.startsWith(scope.pathname)) {
        url = new URL('index.php?page=notifications', scope);
    }
    event.waitUntil((async function() {
        const windows = await self.clients.matchAll({type:'window',includeUncontrolled:true});
        for (const client of windows) {
            if (new URL(client.url).origin === scope.origin
                && new URL(client.url).pathname.startsWith(scope.pathname)) {
                const next = 'navigate' in client ? await client.navigate(url.href) : client;
                if (next && 'focus' in next) return next.focus();
            }
        }
        if (self.clients.openWindow) return self.clients.openWindow(url.href);
    })());
});
