(() => {
    "use strict";

    const form = document.querySelector("#sale-form");
    const root = document.querySelector(".app-shell[data-sync-user]");
    if (!form || !root || !window.fetch || !window.indexedDB) return;

    const DB_NAME = "docan-cashier";
    const STORE_NAME = "pending-transactions";
    const DRAFT_STORE = "transaction-drafts";
    const userKey = `${root.dataset.syncOutlet}:${root.dataset.syncUser}`;
    const statusTemplate = root.dataset.statusUrlTemplate;
    const statusBox = document.querySelector("#transaction-sync-status");
    const statusTitle = document.querySelector("#transaction-sync-title");
    const statusCopy = document.querySelector("#transaction-sync-copy");
    const statusAction = document.querySelector("#transaction-sync-action");
    const pendingCount = document.querySelector("#transaction-pending-count");
    const draftRecovery = document.querySelector("#transaction-draft-recovery");
    const draftRestore = document.querySelector("#transaction-draft-restore");
    const draftDiscard = document.querySelector("#transaction-draft-discard");
    const submitButton = document.querySelector('.confirm-actions .primary-btn[form="sale-form"]');
    let activeRequest = false;
    let actionMode = "check";
    let blockedToken = null;
    let latestDraft = null;
    let lastDraftSignature = "";
    let connectionSlow = false;

    const openDatabase = () => new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 2);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(STORE_NAME)) {
                const store = database.createObjectStore(STORE_NAME, { keyPath: "token" });
                store.createIndex("userKey", "userKey", { unique: false });
            }
            if (!database.objectStoreNames.contains(DRAFT_STORE)) {
                database.createObjectStore(DRAFT_STORE, { keyPath: "userKey" });
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
    const draftOperation = async (mode, work) => {
        const database = await openDatabase();
        return new Promise((resolve, reject) => {
            const transaction = database.transaction(DRAFT_STORE, mode);
            const result = work(transaction.objectStore(DRAFT_STORE));
            transaction.oncomplete = () => { database.close(); resolve(result); };
            transaction.onerror = () => { database.close(); reject(transaction.error); };
        });
    };
    const saveDraft = (draft) => draftOperation("readwrite", (store) => store.put(draft));
    const deleteDraft = () => draftOperation("readwrite", (store) => store.delete(userKey));
    const getDraft = async () => {
        const database = await openDatabase();
        try {
            const transaction = database.transaction(DRAFT_STORE, "readonly");
            return await requestResult(transaction.objectStore(DRAFT_STORE).get(userKey));
        } finally { database.close(); }
    };

    const setStatus = (kind, title, copy, showAction = true) => {
        if (!statusBox) return;
        statusBox.hidden = false;
        statusBox.dataset.kind = kind;
        statusTitle.textContent = title;
        statusCopy.textContent = copy;
        statusAction.hidden = !showAction;
        if (showAction && actionMode === "check") statusAction.textContent = "Cek status transaksi";
    };
    const showOnlineStatus = () => setStatus("online", "Online", "Siap memproses transaksi langsung.", false);
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
            await deleteDraft();
            redirectRecorded(data);
            return true;
        } finally { window.clearTimeout(timeout); }
    };

    const reconcileEntry = async (entry, allowSubmit = true) => {
        try {
            const status = await checkStatus(entry);
            if (status.found) {
                await removePending(entry.token);
                await deleteDraft();
                redirectRecorded(status);
                return true;
            }
            if (allowSubmit) return await submitEntry(entry);
        } catch (error) {
            if (error.permanent) {
                entry.state = "blocked";
                entry.error = error.message;
                entry.updatedAt = Date.now();
                await savePending(entry);
                blockedToken = entry.token;
                actionMode = "discard";
                setStatus("error", "Transaksi perlu diperiksa", error.message, true);
                statusAction.textContent = "Hapus antrean gagal";
                resetSubmit();
                return false;
            }
            throw error;
        }
        return false;
    };

    const deferRetry = async (entries, message) => {
        const now = Date.now();
        await Promise.all(entries.map(async (entry) => {
            if (entry.state === "blocked") return;
            entry.attempts = Number(entry.attempts || 0) + 1;
            entry.updatedAt = now;
            entry.nextRetryAt = now + Math.min(120000, 5000 * (2 ** Math.min(entry.attempts - 1, 4)));
            entry.error = message;
            await savePending(entry);
        }));
    };

    const syncPending = async () => {
        if (activeRequest) return;
        const entries = await pendingForUser();
        if (pendingCount) {
            pendingCount.hidden = entries.length === 0;
            pendingCount.textContent = `${entries.length} pending`;
        }
        if (!entries.length) {
            if (!navigator.onLine) setStatus("offline", "Sedang offline", "Input tetap ada di layar. Hubungkan internet untuk memproses.", false);
            else showOnlineStatus();
            return;
        }
        if (!navigator.onLine) {
            setStatus("offline", "Transaksi menunggu koneksi", `${entries.length} transaksi aman tersimpan di perangkat ini.`, false);
            return;
        }
        const blocked = entries.find((entry) => entry.state === "blocked");
        if (blocked) {
            blockedToken = blocked.token;
            actionMode = "discard";
            setStatus("error", "Sinkronisasi perlu diperiksa", blocked.error || "Stok atau data transaksi berubah.", true);
            statusAction.textContent = "Hapus antrean gagal";
            return;
        }
        actionMode = "check";
        activeRequest = true;
        setStatus("syncing", "Memeriksa transaksi", "Jangan kirim ulang. Docan sedang memastikan status transaksi.", false);
        try {
            const readyEntries = entries.filter((entry) => !entry.nextRetryAt || entry.nextRetryAt <= Date.now());
            if (!readyEntries.length) {
                setStatus("pending", "Menunggu sinkronisasi", "Retry berikutnya dijadwalkan otomatis agar server tidak dibanjiri request.", true);
                return;
            }
            for (const entry of readyEntries.sort((a, b) => a.createdAt - b.createdAt)) {
                if (await reconcileEntry(entry, true)) return;
            }
        } catch (error) {
            await deferRetry(entries, error.message || "Koneksi belum stabil");
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
                entry.state = "blocked";
                entry.error = error.message;
                entry.updatedAt = Date.now();
                await savePending(entry);
                blockedToken = entry.token;
                actionMode = "discard";
                setStatus("error", "Transaksi perlu diperiksa", error.message, true);
                statusAction.textContent = "Hapus antrean gagal";
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
                await deferRetry([entry], "Server belum dapat dijangkau");
                setStatus("pending", "Koneksi belum stabil", "Transaksi aman tersimpan dan akan dicek lagi otomatis.", true);
            }
            resetSubmit();
        }
    });

    const meaningfulPayload = (payload) => {
        if (payload.cart_items && payload.cart_items !== "[]") return true;
        return Boolean(payload.provider && payload.product_type && payload.nominal);
    };
    const captureDraft = async () => {
        if (activeRequest) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        delete payload._token;
        if (!meaningfulPayload(payload)) return;
        const signature = JSON.stringify(payload);
        if (signature === lastDraftSignature) return;
        lastDraftSignature = signature;
        latestDraft = { userKey, payload, savedAt: Date.now() };
        await saveDraft(latestDraft);
    };
    const showDraftRecovery = async () => {
        latestDraft = await getDraft();
        if (!latestDraft || !meaningfulPayload(latestDraft.payload || {})) return;
        const pending = await pendingForUser();
        if (!pending.length) draftRecovery.hidden = false;
    };
    const probeConnection = async () => {
        if (!navigator.onLine || activeRequest) return;
        const started = performance.now();
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);
        try {
            await fetch(root.dataset.connectivityUrl, {
                method: "HEAD",
                cache: "no-store",
                credentials: "same-origin",
                signal: controller.signal,
            });
            const duration = performance.now() - started;
            connectionSlow = duration > 2500;
            const pending = await pendingForUser();
            if (connectionSlow && !pending.length) setStatus("pending", "Koneksi sedang lambat", "Input tersimpan otomatis. Tunggu respons sebelum menekan ulang.", false);
            else if (!pending.length) showOnlineStatus();
        } catch (_error) {
            connectionSlow = true;
            const pending = await pendingForUser();
            if (!pending.length) setStatus("pending", "Koneksi tidak stabil", "Input tersimpan otomatis di perangkat.", false);
        } finally { clearTimeout(timeout); }
    };

    statusAction?.addEventListener("click", async () => {
        if (actionMode === "discard" && blockedToken) {
            await removePending(blockedToken);
            blockedToken = null;
            actionMode = "check";
            await syncPending();
            await showDraftRecovery();
            return;
        }
        syncPending();
    });
    draftRestore?.addEventListener("click", async () => {
        if (!latestDraft) return;
        draftRecovery.hidden = true;
        lastDraftSignature = JSON.stringify(latestDraft.payload);
        document.dispatchEvent(new CustomEvent("docan:restore-draft", { detail: latestDraft }));
    });
    draftDiscard?.addEventListener("click", async () => {
        await deleteDraft();
        latestDraft = null;
        lastDraftSignature = "";
        draftRecovery.hidden = true;
    });
    form.addEventListener("input", captureDraft);
    form.addEventListener("change", captureDraft);
    document.addEventListener("docan:draft-changed", captureDraft);
    window.addEventListener("online", syncPending);
    window.addEventListener("offline", () => setStatus("offline", "Sedang offline", "Transaksi tidak akan hilang. Docan menunggu internet kembali.", false));
    navigator.serviceWorker?.addEventListener("message", (event) => {
        if (event.data?.type === "DOCAN_SYNC_TRANSACTIONS") syncPending();
    });

    showDraftRecovery();
    syncPending();
    probeConnection();
    window.setInterval(captureDraft, 1200);
    window.setInterval(() => { if (navigator.onLine) syncPending(); }, 30000);
    window.setInterval(probeConnection, 45000);
})();
