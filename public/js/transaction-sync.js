(() => {
    "use strict";

    const form = document.querySelector("#sale-form");
    const root = document.querySelector(".app-shell[data-sync-user]");
    if (!form || !root || !window.fetch || !window.indexedDB) return;

    const DB_NAME = "docan-cashier";
    const STORE_NAME = "pending-transactions";
    const userKey = `${root.dataset.syncOutlet}:${root.dataset.syncUser}`;
    const statusTemplate = root.dataset.statusUrlTemplate;
    const statusBox = document.querySelector("#transaction-sync-status");
    const statusTitle = document.querySelector("#transaction-sync-title");
    const statusCopy = document.querySelector("#transaction-sync-copy");
    const statusAction = document.querySelector("#transaction-sync-action");
    const submitButton = document.querySelector('.confirm-actions .primary-btn[form="sale-form"]');
    let activeRequest = false;

    const openDatabase = () => new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(STORE_NAME)) {
                const store = database.createObjectStore(STORE_NAME, { keyPath: "token" });
                store.createIndex("userKey", "userKey", { unique: false });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    const withStore = async (mode, work) => {
        const database = await openDatabase();
        return new Promise((resolve, reject) => {
            const transaction = database.transaction(STORE_NAME, mode);
            const store = transaction.objectStore(STORE_NAME);
            let result;
            try { result = work(store); } catch (error) { reject(error); return; }
            transaction.oncomplete = () => { database.close(); resolve(result); };
            transaction.onerror = () => { database.close(); reject(transaction.error); };
        });
    };

    const requestResult = (request) => new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
    const savePending = (entry) => withStore("readwrite", (store) => store.put(entry));
    const removePending = (token) => withStore("readwrite", (store) => store.delete(token));
    const pendingForUser = async () => {
        const database = await openDatabase();
        try {
            const transaction = database.transaction(STORE_NAME, "readonly");
            return await requestResult(transaction.objectStore(STORE_NAME).index("userKey").getAll(userKey));
        } finally { database.close(); }
    };

    const setStatus = (kind, title, copy, showAction = true) => {
        if (!statusBox) return;
        statusBox.hidden = false;
        statusBox.dataset.kind = kind;
        statusTitle.textContent = title;
        statusCopy.textContent = copy;
        statusAction.hidden = !showAction;
    };
    const hideStatus = () => { if (statusBox) statusBox.hidden = true; };
    const resetSubmit = () => {
        form.dataset.submitting = "false";
        if (submitButton) { submitButton.disabled = false; submitButton.textContent = "Proses sekarang"; }
        activeRequest = false;
    };
    const redirectRecorded = (data) => window.location.assign(data.redirect_url || window.location.href);
    const statusUrl = (token) => statusTemplate.replace("__TOKEN__", encodeURIComponent(token));
    const currentCsrf = () => form.querySelector('input[name="_token"]')?.value || "";

    const checkStatus = async (entry) => {
        const response = await fetch(statusUrl(entry.token), {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        });
        if (!response.ok) throw new Error(`Status HTTP ${response.status}`);
        return response.json();
    };

    const submitEntry = async (entry) => {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 18000);
        const payload = new URLSearchParams(entry.payload);
        payload.set("_token", currentCsrf());
        try {
            const response = await fetch(entry.url, {
                method: "POST",
                body: payload,
                credentials: "same-origin",
                signal: controller.signal,
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest",
                },
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 419 || response.status === 401) {
                throw Object.assign(new Error("Sesi login berakhir. Muat ulang lalu coba lagi."), { permanent: true });
            }
            if (!response.ok) {
                const validation = data.errors ? Object.values(data.errors).flat()[0] : data.message;
                throw Object.assign(new Error(validation || "Transaksi belum dapat diproses."), { permanent: response.status === 422 });
            }
            await removePending(entry.token);
            redirectRecorded(data);
            return true;
        } finally { window.clearTimeout(timeout); }
    };

    const reconcileEntry = async (entry, allowSubmit = true) => {
        try {
            const status = await checkStatus(entry);
            if (status.found) {
                await removePending(entry.token);
                redirectRecorded(status);
                return true;
            }
            if (allowSubmit) return await submitEntry(entry);
        } catch (error) {
            if (error.permanent) {
                await removePending(entry.token);
                setStatus("error", "Transaksi perlu diperiksa", error.message, true);
                resetSubmit();
                return false;
            }
            throw error;
        }
        return false;
    };

    const syncPending = async () => {
        if (activeRequest) return;
        const entries = await pendingForUser();
        if (!entries.length) {
            if (!navigator.onLine) setStatus("offline", "Sedang offline", "Input tetap ada di layar. Hubungkan internet untuk memproses.", false);
            else hideStatus();
            return;
        }
        if (!navigator.onLine) {
            setStatus("offline", "Transaksi menunggu koneksi", `${entries.length} transaksi aman tersimpan di perangkat ini.`, false);
            return;
        }
        activeRequest = true;
        setStatus("syncing", "Memeriksa transaksi", "Jangan kirim ulang. Docan sedang memastikan status transaksi.", false);
        try {
            for (const entry of entries.sort((a, b) => a.createdAt - b.createdAt)) {
                if (await reconcileEntry(entry, entry.state !== "needs_attention")) return;
            }
        } catch (_error) {
            setStatus("pending", "Koneksi belum stabil", "Transaksi tetap aman. Docan akan mencoba lagi otomatis.", true);
        } finally { resetSubmit(); }
    };

    const registerBackgroundSync = async () => {
        try {
            const registration = await navigator.serviceWorker?.ready;
            await registration?.sync?.register("docan-transaction-sync");
        } catch (_error) { /* Online event remains the cross-browser fallback. */ }
    };

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (activeRequest) return;

        const payload = Object.fromEntries(new FormData(form).entries());
        const token = payload.request_token;
        if (!token) { resetSubmit(); form.submit(); return; }
        const entry = {
            token,
            userKey,
            url: form.action,
            payload,
            state: "pending",
            attempts: 0,
            createdAt: Date.now(),
            updatedAt: Date.now(),
        };

        activeRequest = true;
        await savePending(entry);
        await registerBackgroundSync();
        setStatus("syncing", "Memproses transaksi", "Mohon tunggu, jangan menekan tombol berulang kali.", false);

        if (!navigator.onLine) {
            setStatus("offline", "Transaksi menunggu koneksi", "Data aman tersimpan dan akan diproses otomatis saat online.", false);
            resetSubmit();
            return;
        }

        try {
            await submitEntry(entry);
        } catch (error) {
            if (error.permanent) {
                await removePending(entry.token);
                setStatus("error", "Transaksi perlu diperiksa", error.message, true);
                resetSubmit();
                return;
            }
            // A timeout is ambiguous: the server may already have committed it.
            setStatus("pending", "Memastikan status transaksi", "Koneksi lambat. Docan mengecek agar transaksi tidak tercatat dua kali.", false);
            try {
                await new Promise((resolve) => window.setTimeout(resolve, 1800));
                const recorded = await reconcileEntry(entry, false);
                if (!recorded) setStatus("pending", "Transaksi tersimpan", error.permanent ? error.message : "Belum ada jawaban server. Akan dicoba lagi otomatis.", true);
            } catch (_statusError) {
                setStatus("pending", "Koneksi belum stabil", "Transaksi aman tersimpan dan akan dicek lagi otomatis.", true);
            }
            resetSubmit();
        }
    });

    statusAction?.addEventListener("click", syncPending);
    window.addEventListener("online", syncPending);
    window.addEventListener("offline", () => setStatus("offline", "Sedang offline", "Transaksi tidak akan hilang. Docan menunggu internet kembali.", false));
    navigator.serviceWorker?.addEventListener("message", (event) => {
        if (event.data?.type === "DOCAN_SYNC_TRANSACTIONS") syncPending();
    });

    syncPending();
    window.setInterval(() => { if (navigator.onLine) syncPending(); }, 30000);
})();
