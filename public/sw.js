const CACHE = "docan-v137";
const ASSETS = [
    "/css/app.css?v=76",
    "/css/upgrade.css?v=76",
    "/css/flow.css?v=76",
    "/css/detection.css?v=76",
    "/css/admin.css?v=76",
    "/css/direct.css?v=76",
    "/css/premium.css?v=76",
    "/css/docan.css?v=79",
    "/css/notice.css?v=76",
    "/css/reports.css?v=76",
    "/css/typography.css?v=76",
    "/css/accounts.css?v=76",
    "/css/admin-pro.css?v=90",
    "/css/theme-font.css?v=1",
    "/css/ppob.css?v=76",
    "/css/stability.css?v=103",
    "/css/registration.css?v=75",
    "/css/business.css?v=76",
    "/css/business-extra.css?v=78",
    "/js/app.js?v=96",
    "/icon-192.png",
    "/icon-512.png",
    "/manifest.webmanifest",
    "/img/telkomsel.svg",
    "/img/byu.svg",
    "/img/indosat.svg",
    "/img/xl.svg",
    "/img/tri.svg",
    "/img/smartfren-official.svg",
    "/img/mandiri.svg",
    "/img/bri.svg",
    "/img/bni.svg",
    "/img/btn.svg",
    "/img/seabank.svg",
    "/img/bank-jago.svg",
    "/img/icbc.svg",
    "/img/ccb.svg",
    "/img/bank-of-china.svg",
    "/img/axis.svg",
    "/img/dana.webp",
    "/img/ovo.webp",
    "/img/gopay.webp",
    "/img/shopeepay.webp",
    "/img/maxim.svg",
    "/img/linkaja.webp",
    "/img/docan-service.svg",
    "/img/multi.svg",
    "/img/pln.svg",
    "/img/accessories.svg",
    "/img/brilink.svg",
    "/img/ppob.svg",
    "/img/whatsapp.svg",
];
const CACHEABLE_PATHS = new Set(
    ASSETS.map((asset) => new URL(asset, self.location.origin).pathname),
);
self.addEventListener("install", (event) => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(ASSETS)));
});
self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== CACHE)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});
self.addEventListener("fetch", (event) => {
    if (event.request.method !== "GET") return;
    const url = new URL(event.request.url);
    // Never persist authenticated HTML, receipts, reports, or API responses.
    // Only the explicit static asset allow-list is safe for offline caching.
    if (
        url.origin !== self.location.origin ||
        !CACHEABLE_PATHS.has(url.pathname)
    )
        return;
    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const copy = response.clone();
                caches
                    .open(CACHE)
                    .then((cache) => cache.put(event.request, copy));
                return response;
            })
            .catch(() => caches.match(event.request)),
    );
});
