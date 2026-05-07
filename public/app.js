(function () {
    const tokenKey = 'fasatrasporti_token';
    const userKey = 'fasatrasporti_user';
    const redirectDelay = 550;
    const documentCache = new Map();
    const appState = {
        user: null,
        companyDocuments: [],
        documentFilter: 'all',
        employees: [],
        vehicles: [],
    };
    const liveRefreshState = new Map();
    const renderSnapshotState = new Map();
    const statusLabels = {
        missing: 'Mancante',
        pending: 'In attesa',
        approved: 'Approvato',
        rejected: 'Respinto',
        expired: 'Scaduto',
        exemption_pending: 'Esenzione in attesa',
    };

    function qs(selector, scope = document) {
        return scope.querySelector(selector);
    }

    function qsa(selector, scope = document) {
        return Array.from(scope.querySelectorAll(selector));
    }

    function token() {
        return localStorage.getItem(tokenKey) || sessionStorage.getItem(tokenKey);
    }

    function authStorage() {
        return sessionStorage.getItem(tokenKey) ? sessionStorage : localStorage;
    }

    function setAuth(data, remember = true) {
        clearAuth();

        const storage = remember ? localStorage : sessionStorage;
        storage.setItem(tokenKey, data.token);
        storage.setItem(userKey, JSON.stringify(data.user));
    }

    function clearAuth() {
        localStorage.removeItem(tokenKey);
        localStorage.removeItem(userKey);
        sessionStorage.removeItem(tokenKey);
        sessionStorage.removeItem(userKey);
    }

    function sleep(ms) {
        return new Promise((resolve) => {
            window.setTimeout(resolve, ms);
        });
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    async function api(path, options = {}) {
        const headers = {
            Accept: 'application/json',
            ...(options.headers || {}),
        };

        if (token()) {
            headers.Authorization = `Bearer ${token()}`;
        }

        const isFormData = options.body instanceof FormData;

        if (options.body && !isFormData) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }

        const response = await fetch(`/api${path}`, {
            ...options,
            headers,
        });

        const text = await response.text();
        const data = text ? JSON.parse(text) : {};

        if (response.status === 401) {
            clearAuth();
            window.location.href = 'login.html';
            return {};
        }

        if (!response.ok) {
            const errors = data.errors ? Object.values(data.errors).flat().join(' ') : '';
            throw new Error(errors || data.message || 'Operazione non riuscita.');
        }

        return data;
    }

    function apiUpload(path, formData, onProgress = null) {
        return new Promise((resolve, reject) => {
            const request = new XMLHttpRequest();

            request.open('POST', `/api${path}`);
            request.setRequestHeader('Accept', 'application/json');

            if (token()) {
                request.setRequestHeader('Authorization', `Bearer ${token()}`);
            }

            request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable && onProgress) {
                    onProgress(Math.round((event.loaded / event.total) * 100));
                }
            });

            request.addEventListener('load', () => {
                const data = request.responseText ? JSON.parse(request.responseText) : {};

                if (request.status === 401) {
                    clearAuth();
                    window.location.href = 'login.html';
                    resolve({});
                    return;
                }

                if (request.status < 200 || request.status >= 300) {
                    const errors = data.errors ? Object.values(data.errors).flat().join(' ') : '';
                    reject(new Error(errors || data.message || 'Operazione non riuscita.'));
                    return;
                }

                resolve(data);
            });

            request.addEventListener('error', () => reject(new Error('Caricamento non riuscito.')));
            request.send(formData);
        });
    }

    function startLiveRefresh(key, callback, interval = 15000) {
        if (liveRefreshState.has(key)) {
            return;
        }

        let running = false;
        const timer = window.setInterval(async () => {
            if (running || document.hidden) {
                return;
            }

            running = true;

            try {
                await callback({ silent: true });
            } catch (error) {
                console.warn('Live refresh failed', error);
            } finally {
                running = false;
            }
        }, interval);

        liveRefreshState.set(key, timer);
    }

    function shouldSkipSilentRefresh() {
        return Boolean(
            document.body.classList.contains('has-open-modal')
            || qs('form:focus-within')
            || qs('.notification-wrapper.is-open')
            || qs('.profile-drawer-backdrop.is-open')
        );
    }

    function stableStringify(value) {
        return JSON.stringify(value);
    }

    function snapshotKey(options = {}) {
        return options.endpoint || `${options.type || 'company'}:${options.id || 'root'}:${appState.documentFilter}`;
    }

    function captureOpenDocumentState(container) {
        return {
            categories: qsa('[data-document-category-key]', container)
                .filter((element) => element.classList.contains('is-open'))
                .map((element) => element.dataset.documentCategoryKey),
            documents: qsa('[data-document-key]', container)
                .filter((element) => qs('.document-card-details', element)?.classList.contains('is-open'))
                .map((element) => element.dataset.documentKey),
        };
    }

    function restoreOpenDocumentState(container, state) {
        if (!state) {
            return;
        }

        qsa('[data-document-category-key]', container).forEach((element) => {
            const isOpen = state.categories.includes(element.dataset.documentCategoryKey);
            const body = qs('.document-category-body', element);
            const button = qs('[data-toggle-document-category]', element);

            body?.classList.toggle('is-open', isOpen);
            button?.setAttribute('aria-expanded', String(isOpen));
        });

        qsa('[data-document-key]', container).forEach((element) => {
            const isOpen = state.documents.includes(element.dataset.documentKey);
            const details = qs('.document-card-details', element);
            const button = qs('[data-toggle-document-card]', element);

            details?.classList.toggle('is-open', isOpen);
            button?.setAttribute('aria-expanded', String(isOpen));
        });
    }

    function showAlert(message, type = 'error') {
        const alert = qs('[data-alert]');

        if (alert) {
            alert.textContent = message;
            alert.classList.remove('is-success', 'is-error');
            alert.classList.add(type === 'success' ? 'is-success' : 'is-error');
            alert.classList.add('is-visible');
        }
    }

    function hideAlert() {
        const alert = qs('[data-alert]');

        if (alert) {
            alert.classList.remove('is-visible');
        }
    }

    function showToast(message, type = 'success') {
        let container = qs('[data-toast-container]');

        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            container.dataset.toastContainer = '';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast ${type === 'success' ? 'is-success' : 'is-error'}`;
        toast.textContent = message;
        container.appendChild(toast);

        requestAnimationFrame(() => toast.classList.add('is-visible'));

        window.setTimeout(() => {
            toast.classList.remove('is-visible');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 2600);
    }

    function ensureButtonParts(button) {
        if (!button || button.querySelector('.btn-content')) {
            return;
        }

        const content = document.createElement('span');
        content.className = 'btn-content';

        while (button.firstChild) {
            content.appendChild(button.firstChild);
        }

        const loader = document.createElement('span');
        loader.className = 'btn-loader';
        loader.setAttribute('aria-hidden', 'true');

        button.append(content, loader);
    }

    function setButtonLoading(button, isLoading, loadingText = null) {
        if (!button) {
            return;
        }

        ensureButtonParts(button);

        const content = qs('.btn-content', button);

        if (isLoading) {
            if (!button.dataset.originalContent) {
                button.dataset.originalContent = content.innerHTML;
            }

            content.innerHTML = escapeHtml(loadingText || button.dataset.loadingText || 'Caricamento...');
            button.classList.add('is-loading');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            return;
        }

        if (button.dataset.originalContent) {
            content.innerHTML = button.dataset.originalContent;
            delete button.dataset.originalContent;
        }

        button.classList.remove('is-loading');
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }

    function bindButtonAnimations() {
        document.addEventListener('click', (event) => {
            const target = event.target.closest('.btn, .icon-btn, .entity-card, .summary-card, .close-btn, .bottom-nav a, .password-toggle, .mini-action, .link-row a, .filter-chip, .modal-tab, .notification-dismiss');

            if (!target || target.classList.contains('is-loading')) {
                return;
            }

            const rect = target.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const ripple = document.createElement('span');

            ripple.className = 'click-ripple';
            ripple.style.width = `${size}px`;
            ripple.style.height = `${size}px`;
            ripple.style.left = `${event.clientX - rect.left - size / 2}px`;
            ripple.style.top = `${event.clientY - rect.top - size / 2}px`;

            target.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove(), { once: true });
        });
    }

    function bindPasswordToggles(scope = document) {
        qsa('[data-toggle-password]', scope).forEach((button) => {
            if (button.dataset.passwordToggleBound === 'true') {
                return;
            }

            button.dataset.passwordToggleBound = 'true';
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.togglePassword);

                if (!input) {
                    return;
                }

                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                button.classList.toggle('is-visible', isHidden);
                button.setAttribute('aria-label', isHidden ? 'Nascondi password' : 'Mostra password');
                button.setAttribute('title', isHidden ? 'Nascondi password' : 'Mostra password');
                input.focus();
            });
        });
    }

    function requireAuth() {
        if (!token()) {
            window.location.href = 'login.html';
            return false;
        }

        return true;
    }

    function svg(name) {
        const paths = {
            login: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
            plus: '<path d="M12 5v14"/><path d="M5 12h14"/>',
            upload: '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/>',
            logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            user: '<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
            truck: '<path d="M10 17h4V5H2v12h3"/><path d="M14 17h1"/><path d="M14 9h4l4 4v4h-3"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
            search: '<path d="m21 21-4.3-4.3"/><circle cx="11" cy="11" r="8"/>',
            clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
        };

        return `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || ''}</svg>`;
    }

    function statusPill(status) {
        return `<span class="status ${escapeHtml(status)}">${statusLabels[status] || status}</span>`;
    }

    function documentsProgress(entity) {
        const loaded = Number(entity.documents_count || 0);
        const total = Number(entity.required_documents_count || 0);

        return {
            loaded,
            total,
            label: total ? `${loaded}/${total} documenti caricati` : `${loaded} documenti caricati`,
            percent: total ? Math.min(100, Math.round((loaded / total) * 100)) : 0,
        };
    }

    function progressStatus(entity) {
        const progress = documentsProgress(entity);
        const approved = Number(entity.approved_documents_count || 0);

        if (!progress.total || progress.loaded < progress.total) {
            return 'missing';
        }

        if (approved >= progress.total) {
            return 'approved';
        }

        return 'pending';
    }

    function formatDate(value, withTime = false) {
        if (!value) {
            return null;
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return new Intl.DateTimeFormat('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            ...(withTime ? {
                hour: '2-digit',
                minute: '2-digit',
            } : {}),
        }).format(date);
    }

    function expiryText(value) {
        if (!value) {
            return null;
        }

        const today = new Date();
        const expiry = new Date(`${value}T00:00:00`);

        today.setHours(0, 0, 0, 0);

        const diff = Math.ceil((expiry - today) / 86400000);

        if (diff < 0) {
            return `Scaduto da ${Math.abs(diff)} giorni`;
        }

        if (diff === 0) {
            return 'Scade oggi';
        }

        if (diff === 1) {
            return 'Scade domani';
        }

        return `Scade tra ${diff} giorni`;
    }

    function expiryInfo(value) {
        if (!value) {
            return null;
        }

        const today = new Date();
        const expiry = new Date(`${value}T00:00:00`);

        today.setHours(0, 0, 0, 0);

        const days = Math.ceil((expiry - today) / 86400000);
        const label = expiryText(value);
        const tone = days < 0
            ? 'expired'
            : days <= 15
                ? 'critical'
                : days <= 30
                    ? 'warning'
                    : days <= 60
                        ? 'notice'
                        : 'safe';

        return { days, label, tone };
    }

    function expiryBadge(uploaded, status) {
        if (status !== 'approved' || (!uploaded?.expiry_date && !uploaded?.internal_expiry_date)) {
            return '';
        }

        return [
            uploaded.expiry_date ? expiryInfo(uploaded.expiry_date) : null,
            uploaded.internal_expiry_date ? expiryInfo(uploaded.internal_expiry_date) : null,
        ]
            .filter(Boolean)
            .map((info) => `<span class="status expiry ${escapeHtml(info.tone)}">${escapeHtml(info.label)}</span>`)
            .join('');
    }

    function documentMeta(uploaded, status) {
        if (!uploaded) {
            return '';
        }

        const parts = [];
        const uploadedAt = formatDate(uploaded.created_at, true);
        const approvedAt = formatDate(uploaded.approved_at, true);
        const expiry = formatDate(uploaded.expiry_date);
        const internalExpiry = formatDate(uploaded.internal_expiry_date);

        if (uploadedAt) {
            parts.push(`Caricato il ${uploadedAt}`);
        }

        if (status === 'approved' && approvedAt) {
            parts.push(`Approvato il ${approvedAt}`);
        }

        if (status === 'approved' && expiry) {
            parts.push(`Scadenza documento ${expiry}`);
        }

        if (status === 'approved' && internalExpiry) {
            parts.push(`${uploaded.internal_expiry_name || 'Requisito interno'} ${internalExpiry}`);
        }

        if (!parts.length) {
            return '';
        }

        return `<p class="meta">${parts.map(escapeHtml).join(' &middot; ')}</p>`;
    }

    function documentFileLinks(uploaded, options = {}) {
        if (!uploaded?.file_url) {
            return '';
        }

        const files = [uploaded, ...(uploaded.attachments || [])]
            .filter((file) => file?.file_url);

        if (!files.length) {
            return '';
        }

        if (files.length === 1) {
            return `<a class="document-file-link" href="${escapeHtml(files[0].file_url)}" target="_blank" rel="noreferrer">${svg('file')}${escapeHtml(options.singleLabel || 'Apri file')}</a>`;
        }

        return `
            <div class="document-file-list">
                ${files.map((file, index) => `
                    <a class="document-file-link" href="${escapeHtml(file.file_url)}" target="_blank" rel="noreferrer">
                        ${svg('file')}${escapeHtml(index === 0 ? (options.primaryLabel || 'File principale') : (file.integration_name || file.file_name || `Allegato ${index + 1}`))}
                    </a>
                `).join('')}
            </div>
        `;
    }

    function userDisplay() {
        try {
            const user = JSON.parse(sessionStorage.getItem(userKey) || localStorage.getItem(userKey) || '{}');
            return user.name || 'Societa';
        } catch (error) {
            return 'Societa';
        }
    }

    async function loadMe() {
        const data = await api('/me');
        appState.user = data.user;
        authStorage().setItem(userKey, JSON.stringify(data.user));
        qsa('[data-company-name]').forEach((node) => {
            node.textContent = data.user.name;
        });
        qsa('[data-company-email]').forEach((node) => {
            node.textContent = data.user.email;
        });
        fillProfileForm(data.user);
    }

    function fillProfileForm(user) {
        const form = qs('[data-profile-form]');

        if (!form || !user) {
            return;
        }

        ['name', 'responsible_name', 'responsible_phone', 'vat_number', 'email'].forEach((field) => {
            const input = qs(`[name="${field}"]`, form);

            if (input) {
                input.value = user[field] || '';
            }
        });
    }

    function bindProfileForms() {
        const profileForm = qs('[data-profile-form]');
        const passwordForm = qs('[data-profile-password-form]');

        if (profileForm && profileForm.dataset.profileFormBound !== 'true') {
            profileForm.dataset.profileFormBound = 'true';

            profileForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                hideAlert();

                const button = qs('button[type="submit"]', profileForm);
                setButtonLoading(button, true, 'Salvataggio...');

                try {
                    const data = await api('/profile', {
                        method: 'PUT',
                        body: Object.fromEntries(new FormData(profileForm)),
                    });
                    appState.user = data.user;
                    authStorage().setItem(userKey, JSON.stringify(data.user));
                    await loadMe();
                    showAlert('Profilo aggiornato.', 'success');
                } catch (error) {
                    showAlert(error.message);
                } finally {
                    setButtonLoading(button, false);
                }
            });
        }

        if (passwordForm && passwordForm.dataset.profilePasswordFormBound !== 'true') {
            passwordForm.dataset.profilePasswordFormBound = 'true';
            const advisor = bindPasswordAdvisor(passwordForm);

            passwordForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                hideAlert();

                if (advisor && !advisor.isValid()) {
                    showAlert('La nuova password non rispetta tutti i requisiti.');
                    return;
                }

                const button = qs('button[type="submit"]', passwordForm);
                setButtonLoading(button, true, 'Aggiornamento...');

                try {
                    await api('/profile/password', {
                        method: 'PUT',
                        body: Object.fromEntries(new FormData(passwordForm)),
                    });
                    passwordForm.reset();
                    advisor?.render();
                    showAlert('Password aggiornata.', 'success');
                } catch (error) {
                    showAlert(error.message);
                } finally {
                    setButtonLoading(button, false);
                }
            });
        }
    }

    function renderDocuments(container, documents, options = {}) {
        const key = snapshotKey(options);
        const payloadSnapshot = stableStringify(documents);
        const previousSnapshot = renderSnapshotState.get(key);
        const shouldRestoreOpenState = previousSnapshot !== undefined;

        if (options.silent && previousSnapshot === payloadSnapshot) {
            return;
        }

        const openState = shouldRestoreOpenState ? captureOpenDocumentState(container) : null;

        if (!documents.length) {
            container.innerHTML = `<div class="empty-state">${escapeHtml(options.emptyMessage || 'Nessun template disponibile.')}</div>`;
            renderSnapshotState.set(key, payloadSnapshot);
            return;
        }

        const primaryDocuments = documents.filter((item) => !item.template?.category);
        const categoryGroups = documents
            .filter((item) => item.template?.category)
            .reduce((groups, item) => {
                const category = item.template.category;
                const key = String(category.id);

                if (!groups.has(key)) {
                    groups.set(key, {
                        category,
                        documents: [],
                    });
                }

                groups.get(key).documents.push(item);

                return groups;
            }, new Map());

        const renderDocumentItem = (item) => {
            const uploaded = item.uploaded_document;
            const status = item.status || 'missing';
            const note = uploaded?.admin_notes
                ? `<p class="document-note">Note: ${escapeHtml(uploaded.admin_notes)}</p>`
                : '';
            const file = documentFileLinks(uploaded);
            const dates = documentMeta(uploaded, status);
            const upload = ['missing', 'rejected', 'expired'].includes(status)
                ? uploadForm(item.template.id, null, options.type, options.id, true)
                : '';
            const replaceUpload = status === 'approved'
                ? uploadForm(item.template.id, null, options.type, options.id, true, 'Sostituisci file')
                : '';
            const integration = status === 'approved'
                ? integrationForm(item.template.id, options.type, options.id)
                : '';
            const exemption = ['missing', 'rejected', 'expired'].includes(status)
                ? exemptionForm(item.template.id, null, options.type, options.id)
                : '';
            const subdocuments = item.subdocuments || [];
            const subdocumentsHtml = subdocuments.length ? `
                <div class="subdocument-list">
                    <div class="subdocument-list-title">Sottodocumenti</div>
                    ${subdocuments.map((subitem) => {
                        const subUploaded = subitem.uploaded_document;
                        const subStatus = subitem.status || 'missing';
                        const subNote = subUploaded?.admin_notes
                            ? `<p class="document-note">Note: ${escapeHtml(subUploaded.admin_notes)}</p>`
                            : '';
                        const subFile = documentFileLinks(subUploaded, {
                            singleLabel: 'Apri file',
                            primaryLabel: 'File principale',
                        });
                        const subUpload = ['missing', 'rejected', 'expired'].includes(subStatus)
                            ? uploadForm(item.template.id, subitem.subtemplate.id, options.type, options.id, false)
                            : '';
                        const subExemption = ['missing', 'rejected', 'expired'].includes(subStatus)
                            ? exemptionForm(item.template.id, subitem.subtemplate.id, options.type, options.id)
                            : '';
                        const subExemptionNote = subitem.exemption?.admin_notes && subitem.exemption.status === 'rejected'
                            ? `<p class="document-note danger">Esenzione rifiutata: ${escapeHtml(subitem.exemption.admin_notes)}</p>`
                            : '';
                        const subPendingExemption = subStatus === 'exemption_pending'
                            ? '<p class="document-note neutral">Richiesta inviata. Attendi conferma dell&apos;admin.</p>'
                            : '';
                        const subDescription = subitem.subtemplate.description
                            ? `<p class="subdocument-description">${escapeHtml(subitem.subtemplate.description)}</p>`
                            : '';
                        const hasSubAction = Boolean(subUpload || subExemption);

                        return `
                            <div class="subdocument-item is-${escapeHtml(subStatus)}">
                                <div class="subdocument-header">
                                    <div>
                                        <strong>${escapeHtml(subitem.subtemplate.name)}</strong>
                                        <span class="optional-chip">Opzionale</span>
                                        ${subDescription}
                                    </div>
                                    ${statusPill(subStatus)}
                                </div>
                                ${subFile || subNote || subExemptionNote || subPendingExemption ? `
                                    <div class="subdocument-body">
                                        ${subFile}
                                        ${subNote}
                                        ${subExemptionNote}
                                        ${subPendingExemption}
                                    </div>
                                ` : ''}
                                ${hasSubAction ? `
                                    <div class="document-actions subdocument-actions">
                                        ${subUpload}
                                        ${subExemption}
                                    </div>
                                ` : ''}
                            </div>
                        `;
                    }).join('')}
                </div>
            ` : '';
            const exemptionNote = item.exemption?.admin_notes && item.exemption.status === 'rejected'
                ? `<p class="document-note danger">Esenzione rifiutata: ${escapeHtml(item.exemption.admin_notes)}</p>`
                : '';
            const pendingExemption = status === 'exemption_pending'
                ? '<p class="document-note neutral">Richiesta inviata. Attendi conferma dell&apos;admin: se viene approvata, questo documento non sara piu richiesto.</p>'
                : '';
            const expiredNote = status === 'expired'
                ? '<p class="document-note danger">Documento scaduto: carica una nuova versione aggiornata.</p>'
                : '';
            const integrations = uploaded?.integrations || [];
            const integrationsHtml = integrations.length ? `
                <div class="subdocument-list">
                    <div class="subdocument-list-title">Integrazioni richieste</div>
                    ${integrations.map((document) => `
                        <div class="subdocument-item is-${escapeHtml(document.effective_status || document.status || 'pending')}">
                            <div class="subdocument-header">
                                <div>
                                    <strong>${escapeHtml(document.integration_name || 'Integrazione')}</strong>
                                    ${document.integration_notes ? `<p class="subdocument-description">${escapeHtml(document.integration_notes)}</p>` : ''}
                                </div>
                                ${statusPill(document.effective_status || document.status || 'pending')}
                            </div>
                            <div class="subdocument-body">
                                ${document.file_url ? `<a class="document-file-link" href="${escapeHtml(document.file_url)}" target="_blank" rel="noreferrer">${svg('file')}Apri file</a>` : ''}
                                ${document.admin_notes ? `<p class="document-note">Note: ${escapeHtml(document.admin_notes)}</p>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            ` : '';
            const hasAction = Boolean(upload || exemption || replaceUpload || integration);
            const documentKey = `${item.template.id}:${item.uploaded_document?.id || 'missing'}:${status}`;
            const defaultOpen = ['missing', 'rejected', 'expired', 'exemption_pending'].includes(status);

            return `
                <article class="document-card is-${escapeHtml(status)}" data-document-key="${escapeHtml(documentKey)}">
                    <div class="document-card-main">
                        <button class="document-card-header" type="button" data-toggle-document-card aria-expanded="${defaultOpen ? 'true' : 'false'}">
                            <div class="document-heading">
                                <h3 class="document-title">${escapeHtml(item.template.name)}</h3>
                                ${dates}
                            </div>
                            <div class="document-badges">
                                ${statusPill(status)}
                                ${expiryBadge(uploaded, status)}
                                <span class="document-toggle" aria-hidden="true">Apri</span>
                            </div>
                        </button>
                        <div class="document-card-details ${defaultOpen ? 'is-open' : ''}">
                            <div class="document-card-body">
                            ${file}
                            ${note}
                            ${expiredNote}
                            ${exemptionNote}
                            ${pendingExemption}
                            ${subdocumentsHtml}
                            ${integrationsHtml}
                            </div>
                            ${hasAction ? `
                                <div class="document-actions">
                                    ${upload}
                                    ${exemption}
                                    ${replaceUpload}
                                    ${integration}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                </article>
            `;
        };

        container.innerHTML = `
            ${primaryDocuments.map(renderDocumentItem).join('')}
            ${Array.from(categoryGroups.values()).map((group) => `
                <section class="document-category-block" data-document-category-key="${escapeHtml(String(group.category.id))}">
                    <button class="document-category-header" type="button" data-toggle-document-category aria-expanded="false">
                        <span>${escapeHtml(group.category.name)}</span>
                        <strong>${group.documents.length}</strong>
                    </button>
                    ${group.category.description ? `<p class="document-category-description">${escapeHtml(group.category.description)}</p>` : ''}
                    <div class="document-category-body">
                        ${group.documents.map(renderDocumentItem).join('')}
                    </div>
                </section>
            `).join('')}
        `;

        restoreOpenDocumentState(container, openState);
        renderSnapshotState.set(key, payloadSnapshot);

        qsa('[data-toggle-document-category]', container).forEach((button) => {
            const body = qs('.document-category-body', button.closest('.document-category-block'));

            button.addEventListener('click', () => {
                const isOpen = !body?.classList.contains('is-open');
                body?.classList.toggle('is-open', isOpen);
                button.setAttribute('aria-expanded', String(isOpen));
            });
        });

        qsa('[data-toggle-document-card]', container).forEach((button) => {
            const details = qs('.document-card-details', button.closest('.document-card'));

            button.addEventListener('click', () => {
                const isOpen = !details?.classList.contains('is-open');
                details?.classList.toggle('is-open', isOpen);
                button.setAttribute('aria-expanded', String(isOpen));
            });
        });

        qsa('[data-upload-form]', container).forEach((form) => {
            bindFileInputs(form);
            bindExpiryToggle(form);
            bindInternalExpiryToggle(form);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                hideAlert();

                const button = qs('button[type="submit"]', form);
                setButtonLoading(button, true, 'Caricamento...');
                updateUploadProgress(form, 0);

                try {
                    const formData = new FormData(form);
                    await apiUpload('/documents', formData, (progress) => updateUploadProgress(form, progress));
                    documentCache.delete(options.endpoint || '');
                    showAlert(form.querySelector('[name="subtemplate_id"]') ? 'File del sottodocumento caricati.' : 'Documento caricato.', 'success');
                    await options.refresh();
                } catch (error) {
                    showAlert(error.message);
                } finally {
                    setButtonLoading(button, false);
                }
            });
        });

        qsa('[data-exemption-form]', container).forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                hideAlert();

                const button = qs('button[type="submit"]', form);
                setButtonLoading(button, true, 'Invio richiesta...');

                try {
                    await api('/document-exemptions', {
                        method: 'POST',
                        body: Object.fromEntries(new FormData(form)),
                    });
                    documentCache.delete(options.endpoint || '');
                    showAlert('Richiesta di esenzione inviata.', 'success');
                    await options.refresh();
                } catch (error) {
                    showAlert(error.message);
                } finally {
                    setButtonLoading(button, false);
                }
            });
        });

        qsa('[data-integration-form]', container).forEach((form) => {
            bindFileInputs(form);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                hideAlert();

                const button = qs('button[type="submit"]', form);
                setButtonLoading(button, true, 'Invio integrazioni...');
                updateUploadProgress(form, 0);

                try {
                    const formData = new FormData(form);
                    await apiUpload('/document-integrations', formData, (progress) => updateUploadProgress(form, progress));
                    documentCache.delete(options.endpoint || '');
                    showAlert('Integrazioni inviate.', 'success');
                    await options.refresh();
                } catch (error) {
                    showAlert(error.message);
                } finally {
                    setButtonLoading(button, false);
                }
            });
        });
    }

    function uploadForm(templateId, subtemplateId, type, id, allowExpiry = true, buttonLabel = 'Carica') {
        const isMultiSubdocument = Boolean(subtemplateId);

        return `
            <form class="upload-form" data-upload-form>
                <input type="hidden" name="template_id" value="${templateId}">
                ${subtemplateId ? `<input type="hidden" name="subtemplate_id" value="${subtemplateId}">` : ''}
                <input type="hidden" name="documentable_type" value="${escapeHtml(type)}">
                ${id ? `<input type="hidden" name="documentable_id" value="${escapeHtml(id)}">` : ''}
                <div class="field file-field">
                    <label>File</label>
                    <label class="file-picker">
                        <input class="file-input" type="file" name="${isMultiSubdocument ? 'files[]' : 'file'}" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" ${isMultiSubdocument ? 'multiple' : ''} required data-file-input>
                        <span class="file-picker-icon">${svg('upload')}</span>
                        <span data-file-name>${isMultiSubdocument ? 'Seleziona uno o piu PDF/DOC' : 'Trascina qui o seleziona PDF/DOC'}</span>
                    </label>
                </div>
                ${allowExpiry ? `
                    <div class="field">
                        <label>Scadenza documento</label>
                        <select class="input" name="has_expiry" data-has-expiry required>
                            <option value="0">No, non ha scadenza</option>
                            <option value="1">Si, ha scadenza</option>
                        </select>
                    </div>
                    <div class="field" data-expiry-date-field hidden>
                        <label>Data scadenza</label>
                        <input class="input" type="date" name="expiry_date" data-expiry-date>
                    </div>
                    <div class="field">
                        <label>Seconda scadenza</label>
                        <select class="input" data-has-internal-expiry>
                            <option value="0">No</option>
                            <option value="1">Si, requisito interno</option>
                        </select>
                    </div>
                    <div class="field" data-internal-expiry-field hidden>
                        <label>Nome requisito</label>
                        <input class="input" type="text" name="internal_expiry_name" maxlength="255" placeholder="Es. CQC" data-internal-expiry-name>
                    </div>
                    <div class="field" data-internal-expiry-field hidden>
                        <label>Scadenza requisito</label>
                        <input class="input" type="date" name="internal_expiry_date" data-internal-expiry-date>
                    </div>
                ` : '<input type="hidden" name="has_expiry" value="0">'}
                <div class="upload-progress" data-upload-progress><span></span></div>
                <button class="btn" type="submit" data-loading-text="Caricamento..."><span class="btn-content">${svg('upload')}${escapeHtml(buttonLabel)}</span><span class="btn-loader" aria-hidden="true"></span></button>
            </form>
        `;
    }

    function integrationForm(templateId, type, id) {
        return `
            <form class="integration-form" data-integration-form>
                <input type="hidden" name="template_id" value="${templateId}">
                <input type="hidden" name="documentable_type" value="${escapeHtml(type)}">
                ${id ? `<input type="hidden" name="documentable_id" value="${escapeHtml(id)}">` : ''}
                <div class="field">
                    <label>Nota integrazione</label>
                    <textarea class="input" name="integration_notes" rows="2" placeholder="Facoltativa"></textarea>
                </div>
                <div class="field file-field">
                    <label>File integrazioni</label>
                    <label class="file-picker">
                        <input class="file-input" type="file" name="files[]" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required multiple data-file-input>
                        <span class="file-picker-icon">${svg('upload')}</span>
                        <span data-file-name>Seleziona uno o piu PDF/DOC</span>
                    </label>
                </div>
                <div class="upload-progress" data-upload-progress><span></span></div>
                <button class="btn secondary" type="submit" data-loading-text="Invio integrazioni..."><span class="btn-content">${svg('plus')}Invia integrazioni</span><span class="btn-loader" aria-hidden="true"></span></button>
            </form>
        `;
    }

    function exemptionForm(templateId, subtemplateId, type, id) {
        return `
            <form class="exemption-form" data-exemption-form>
                <input type="hidden" name="template_id" value="${templateId}">
                ${subtemplateId ? `<input type="hidden" name="subtemplate_id" value="${subtemplateId}">` : ''}
                <input type="hidden" name="documentable_type" value="${escapeHtml(type)}">
                ${id ? `<input type="hidden" name="documentable_id" value="${escapeHtml(id)}">` : ''}
                <div class="field">
                    <label>Motivo esenzione</label>
                    <textarea class="input" name="requested_reason" rows="2" placeholder="Facoltativo"></textarea>
                </div>
                <button class="btn secondary" type="submit" data-loading-text="Invio richiesta..."><span class="btn-content">Richiedi esenzione</span><span class="btn-loader" aria-hidden="true"></span></button>
            </form>
        `;
    }

    function bindExpiryToggle(form) {
        const select = qs('[data-has-expiry]', form);
        const dateField = qs('[data-expiry-date-field]', form);
        const dateInput = qs('[data-expiry-date]', form);

        if (!select || !dateField || !dateInput) {
            return;
        }

        const sync = () => {
            const hasExpiry = select.value === '1';
            dateField.hidden = !hasExpiry;
            dateInput.required = hasExpiry;

            if (!hasExpiry) {
                dateInput.value = '';
            }
        };

        select.addEventListener('change', sync);
        sync();
    }

    function bindInternalExpiryToggle(form) {
        const select = qs('[data-has-internal-expiry]', form);
        const fields = qsa('[data-internal-expiry-field]', form);
        const nameInput = qs('[data-internal-expiry-name]', form);
        const dateInput = qs('[data-internal-expiry-date]', form);

        if (!select || !fields.length || !nameInput || !dateInput) {
            return;
        }

        const sync = () => {
            const hasInternalExpiry = select.value === '1';
            fields.forEach((field) => {
                field.hidden = !hasInternalExpiry;
            });
            nameInput.required = hasInternalExpiry;
            dateInput.required = hasInternalExpiry;

            if (!hasInternalExpiry) {
                nameInput.value = '';
                dateInput.value = '';
            }
        };

        select.addEventListener('change', sync);
        sync();
    }

    function bindFileInputs(scope = document) {
        qsa('[data-file-input]', scope).forEach((input) => {
            input.addEventListener('change', () => {
                const filePicker = input.closest('.file-picker');
                const count = input.files?.length || 0;
                const name = count > 1 ? `${count} file selezionati` : (input.files?.[0]?.name || 'Trascina qui o seleziona file');
                const label = qs('[data-file-name]', filePicker);

                if (label) {
                    label.textContent = name;
                }

                filePicker?.classList.toggle('has-file', Boolean(input.files?.length));
            });

            const filePicker = input.closest('.file-picker');

            if (!filePicker) {
                return;
            }

            ['dragenter', 'dragover'].forEach((eventName) => {
                filePicker.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    filePicker.classList.add('is-dragging');
                });
            });

            ['dragleave', 'drop'].forEach((eventName) => {
                filePicker.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    filePicker.classList.remove('is-dragging');
                });
            });

            filePicker.addEventListener('drop', (event) => {
                const files = event.dataTransfer?.files;

                if (!files?.length) {
                    return;
                }

                if (input.multiple) {
                    input.files = files;
                } else {
                    const transfer = new DataTransfer();
                    transfer.items.add(files[0]);
                    input.files = transfer.files;
                }

                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    }

    function updateUploadProgress(scope, progress) {
        const bar = qs('[data-upload-progress] span', scope);

        if (!bar) {
            return;
        }

        bar.style.width = `${Math.max(0, Math.min(100, progress))}%`;
    }

    async function initLogin() {
        const form = qs('[data-login-form]');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideAlert();

            const body = Object.fromEntries(new FormData(form));
            const remember = body.remember === 'on';
            delete body.remember;

            const button = qs('button[type="submit"]', form);
            setButtonLoading(button, true);

            try {
                const data = await api('/login', {
                    method: 'POST',
                    body,
                });
                setAuth(data, remember);
                showAlert('Accesso effettuato.', 'success');
                await sleep(redirectDelay);
                window.location.href = 'index.html';
            } catch (error) {
                showAlert(error.message);
                setButtonLoading(button, false);
            }
        });
    }

    async function initRegister() {
        const form = qs('[data-register-form]');
        const passwordAdvisor = bindPasswordAdvisor(form);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideAlert();

            if (passwordAdvisor && !passwordAdvisor.isValid()) {
                showAlert('La password non rispetta tutti i requisiti.');
                return;
            }

            const body = Object.fromEntries(new FormData(form));
            const remember = body.remember === 'on';
            delete body.remember;

            const button = qs('button[type="submit"]', form);
            setButtonLoading(button, true);

            try {
                await api('/register', {
                    method: 'POST',
                    body,
                });
                form.reset();
                passwordAdvisor?.render();
                showAlert('Registrazione effettuata. Attendi l approvazione dell admin prima di accedere.', 'success');
            } catch (error) {
                showAlert(error.message);
            } finally {
                setButtonLoading(button, false);
            }
        });
    }

    function bindPasswordAdvisor(form) {
        const password = qs('[data-password-input]', form);
        const confirmation = qs('[data-password-confirmation]', form);
        const generator = qs('[data-generate-password]', form);
        const advisor = qs('[data-password-advisor]', form);
        const meter = qs('[data-password-meter]', form);

        if (!password || !confirmation || !advisor || !meter) {
            return null;
        }

        const render = () => {
            const checks = passwordChecks(password.value, confirmation.value);
            const validCount = Object.values(checks).filter(Boolean).length;
            const percent = Math.round((validCount / Object.keys(checks).length) * 100);

            Object.entries(checks).forEach(([key, isValid]) => {
                qs(`[data-password-rule="${key}"]`, advisor)?.classList.toggle('is-valid', isValid);
            });

            meter.style.width = `${percent}%`;
            advisor.classList.toggle('is-medium', percent >= 60 && percent < 100);
            advisor.classList.toggle('is-strong', percent === 100);
        };

        password.addEventListener('input', render);
        confirmation.addEventListener('input', render);

        generator?.addEventListener('click', () => {
            const generated = generatePassword();

            password.value = generated;
            confirmation.value = generated;
            password.dispatchEvent(new Event('input', { bubbles: true }));
            confirmation.dispatchEvent(new Event('input', { bubbles: true }));
            showToast('Password generata.', 'success');
        });

        render();

        return {
            render,
            isValid: () => Object.values(passwordChecks(password.value, confirmation.value)).every(Boolean),
        };
    }

    function passwordChecks(password, confirmation) {
        return {
            length: password.length >= 8,
            lower: /[a-z]/.test(password),
            upper: /[A-Z]/.test(password),
            number: /\d/.test(password),
            match: password.length > 0 && password === confirmation,
        };
    }

    function generatePassword() {
        const groups = [
            'abcdefghijkmnopqrstuvwxyz',
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            '23456789',
            '!@#$%&*?',
        ];
        const length = 14;
        const chars = groups.join('');
        const password = groups.map((group) => group[randomIndex(group.length)]);

        while (password.length < length) {
            password.push(chars[randomIndex(chars.length)]);
        }

        return shuffle(password).join('');
    }

    function randomIndex(max) {
        if (window.crypto?.getRandomValues) {
            const value = new Uint32Array(1);
            window.crypto.getRandomValues(value);

            return value[0] % max;
        }

        return Math.floor(Math.random() * max);
    }

    function shuffle(items) {
        const values = [...items];

        for (let index = values.length - 1; index > 0; index -= 1) {
            const swapIndex = randomIndex(index + 1);
            [values[index], values[swapIndex]] = [values[swapIndex], values[index]];
        }

        return values;
    }

    async function initDashboard() {
        if (!requireAuth()) {
            return;
        }

        qsa('[data-company-name]').forEach((node) => {
            node.textContent = userDisplay();
        });

        await loadMe();
        bindCompanyChrome();
        bindDashboardFilters();
        await refreshDashboardPage();
        startLiveRefresh('dashboard', refreshDashboardPage);
    }

    function bindDashboardFilters() {
        qsa('[data-document-filter]').forEach((button) => {
            button.addEventListener('click', () => {
                setDocumentFilter(button.dataset.documentFilter || 'all');
            });
        });
    }

    function setDocumentFilter(filter, options = {}) {
        appState.documentFilter = filter;

        qsa('[data-document-filter]').forEach((item) => {
            item.classList.toggle('is-active', item.dataset.documentFilter === filter);
        });

        qsa('[data-summary-filter]').forEach((item) => {
            const isActive = item.dataset.summaryFilter === filter;
            item.classList.toggle('is-active', isActive);
            item.setAttribute('aria-pressed', String(isActive));
        });

        renderCompanyDocuments({ silent: Boolean(options.silent) });
    }

    function ensureProfileDrawer() {
        let drawer = qs('[data-profile-drawer]');

        if (drawer) {
            return drawer;
        }

        document.body.insertAdjacentHTML('beforeend', `
            <aside class="profile-drawer-backdrop" data-profile-drawer aria-hidden="true">
                <section class="profile-drawer" aria-label="Profilo societ&agrave;">
                    <div class="profile-drawer-head">
                        <div>
                            <h2>Profilo societ&agrave;</h2>
                            <p data-company-email></p>
                        </div>
                        <button class="close-btn" type="button" data-close-profile aria-label="Chiudi profilo">
                            ${svg('x')}
                        </button>
                    </div>

                    <div class="profile-drawer-body">
                        <form class="profile-form" data-profile-form>
                            <div class="field">
                                <label>Ragione sociale</label>
                                <input class="input" name="name" type="text" required>
                            </div>
                            <div class="field">
                                <label>Responsabile</label>
                                <input class="input" name="responsible_name" type="text">
                            </div>
                            <div class="field">
                                <label>Telefono responsabile</label>
                                <input class="input" name="responsible_phone" type="tel">
                            </div>
                            <div class="field">
                                <label>Partita IVA</label>
                                <input class="input" name="vat_number" type="text">
                            </div>
                            <div class="field">
                                <label>Email</label>
                                <input class="input" name="email" type="email" required>
                            </div>
                            <button class="btn secondary" type="submit" data-loading-text="Salvataggio...">
                                <span class="btn-content">Salva profilo</span>
                                <span class="btn-loader" aria-hidden="true"></span>
                            </button>
                        </form>

                        <form class="password-change-form" data-profile-password-form>
                            <h3>Password</h3>
                            <div class="field">
                                <label>Password attuale</label>
                                <div class="password-field">
                                    <input class="input" id="profile_current_password" name="current_password" type="password" autocomplete="current-password" required>
                                    <button class="password-toggle" type="button" data-toggle-password="profile_current_password" aria-label="Mostra password" title="Mostra password">
                                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-3.2 4.2"/><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 8 10 8a10.4 10.4 0 0 0 5.4-1.5"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="field">
                                <div class="field-label-row">
                                    <label>Nuova password</label>
                                    <button class="mini-action" type="button" data-generate-password>Genera</button>
                                </div>
                                <div class="password-field">
                                    <input class="input" id="profile_password" name="password" type="password" autocomplete="new-password" required data-password-input>
                                    <button class="password-toggle" type="button" data-toggle-password="profile_password" aria-label="Mostra password" title="Mostra password">
                                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-3.2 4.2"/><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 8 10 8a10.4 10.4 0 0 0 5.4-1.5"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="field">
                                <label>Conferma password</label>
                                <div class="password-field">
                                    <input class="input" id="profile_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required data-password-confirmation>
                                    <button class="password-toggle" type="button" data-toggle-password="profile_password_confirmation" aria-label="Mostra password" title="Mostra password">
                                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-3.2 4.2"/><path d="M6.6 6.6C3.6 8.6 2 12 2 12s3.5 8 10 8a10.4 10.4 0 0 0 5.4-1.5"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="password-advisor" data-password-advisor>
                                <div class="password-meter" aria-hidden="true"><span data-password-meter></span></div>
                                <ul class="password-rules">
                                    <li data-password-rule="length">Almeno 8 caratteri</li>
                                    <li data-password-rule="lower">Una lettera minuscola</li>
                                    <li data-password-rule="upper">Una lettera maiuscola</li>
                                    <li data-password-rule="number">Un numero</li>
                                    <li data-password-rule="match">Le password coincidono</li>
                                </ul>
                            </div>
                            <button class="btn secondary" type="submit" data-loading-text="Aggiornamento...">
                                <span class="btn-content">Aggiorna password</span>
                                <span class="btn-loader" aria-hidden="true"></span>
                            </button>
                        </form>
                    </div>
                </section>
            </aside>
        `);

        drawer = qs('[data-profile-drawer]');
        bindPasswordToggles(drawer);

        if (appState.user) {
            qsa('[data-company-email]').forEach((node) => {
                node.textContent = appState.user.email || '';
            });
        }

        fillProfileForm(appState.user);

        return drawer;
    }

    function bindProfileDrawer() {
        const drawer = ensureProfileDrawer();
        const openButtons = qsa('[data-open-profile]');

        if (!drawer || !openButtons.length) {
            return;
        }

        const setOpen = (isOpen) => {
            drawer.classList.toggle('is-open', isOpen);
            drawer.setAttribute('aria-hidden', String(!isOpen));
            document.body.classList.toggle('has-drawer-open', isOpen);
        };

        openButtons.forEach((button) => {
            if (button.dataset.profileOpenBound === 'true') {
                return;
            }

            button.dataset.profileOpenBound = 'true';
            button.addEventListener('click', () => setOpen(true));
        });

        if (drawer.dataset.profileDrawerBound === 'true') {
            return;
        }

        drawer.dataset.profileDrawerBound = 'true';

        qsa('[data-close-profile]', drawer).forEach((button) => {
            button.addEventListener('click', () => setOpen(false));
        });

        drawer.addEventListener('click', (event) => {
            if (event.target === drawer) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
                setOpen(false);
            }
        });
    }

    function bindCompanyChrome() {
        bindProfileDrawer();
        bindProfileForms();
        bindNotifications();
        refreshNotifications({ silent: true });
        startLiveRefresh('notifications', refreshNotifications, 12000);
        bindLogout();
    }

    function bindNotifications() {
        qsa('[data-notifications]').forEach((wrapper) => {
            if (wrapper.dataset.notificationsBound === 'true') {
                return;
            }

            wrapper.dataset.notificationsBound = 'true';

            const toggle = qs('[data-open-notifications]', wrapper);
            const panel = qs('[data-notification-panel]', wrapper);
            const refresh = qs('[data-refresh-notifications]', wrapper);
            const clear = qs('[data-clear-notifications]', wrapper);

            if (!toggle || !panel) {
                return;
            }

            const setOpen = (isOpen) => {
                wrapper.classList.toggle('is-open', isOpen);
                panel.setAttribute('aria-hidden', String(!isOpen));
                toggle.setAttribute('aria-expanded', String(isOpen));
            };

            toggle.addEventListener('click', () => {
                const willOpen = !wrapper.classList.contains('is-open');
                setOpen(willOpen);

                if (willOpen) {
                    refreshNotifications();
                }
            });

            refresh?.addEventListener('click', () => refreshNotifications());
            clear?.addEventListener('click', async () => {
                setButtonLoading(clear, true, 'Pulizia...');

                try {
                    await api('/notifications', { method: 'DELETE' });
                    renderNotifications({ unread_count: 0, notifications: [] });
                    showToast('Notifiche eliminate.', 'success');
                } catch (error) {
                    showToast(error.message, 'error');
                } finally {
                    setButtonLoading(clear, false);
                }
            });

            document.addEventListener('click', (event) => {
                if (!wrapper.contains(event.target)) {
                    setOpen(false);
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    setOpen(false);
                }
            });
        });
    }

    async function refreshNotifications(options = {}) {
        const lists = qsa('[data-notification-list]');
        const silent = Boolean(options.silent);

        if (!lists.length) {
            return;
        }

        if (silent && shouldSkipSilentRefresh()) {
            return;
        }

        if (!silent) {
            lists.forEach((list) => {
                list.innerHTML = '<div class="notification-empty">Caricamento...</div>';
            });
        }

        try {
            const data = await api('/notifications');
            renderNotifications(data);
        } catch (error) {
            if (!silent) {
                lists.forEach((list) => {
                    list.innerHTML = `<div class="notification-empty">${escapeHtml(error.message)}</div>`;
                });
            }
        }
    }

    function renderNotifications(data) {
        const notifications = data.notifications || [];
        const count = Number(data.unread_count || 0);

        qsa('[data-notification-count]').forEach((badge) => {
            badge.hidden = count <= 0;
            badge.textContent = count > 9 ? '9+' : String(count);
        });

        qsa('[data-notification-list]').forEach((list) => {
            if (!notifications.length) {
                list.innerHTML = '<div class="notification-empty">Nessuna notifica.</div>';
                return;
            }

            list.innerHTML = notifications.map((notification) => `
                <article class="notification-item ${escapeHtml(notification.type)}" data-notification-id="${escapeHtml(notification.id)}">
                    <a class="notification-link" href="${escapeHtml(notification.target || 'index.html')}">
                        <span class="notification-mark" aria-hidden="true">${notificationIcon(notification.type)}</span>
                        <span class="notification-copy">
                            <strong>${escapeHtml(notification.title)}</strong>
                            <span>${escapeHtml(notification.subtitle)}</span>
                            <small>${escapeHtml(notification.body || '')}${notification.date ? ` &middot; ${escapeHtml(formatDate(notification.date, notification.date.includes('T')) || notification.date)}` : ''}</small>
                        </span>
                    </a>
                    <button class="notification-dismiss" type="button" data-dismiss-notification="${escapeHtml(notification.id)}" title="Elimina notifica" aria-label="Elimina notifica">
                        ${svg('x')}
                    </button>
                </article>
            `).join('');

            qsa('[data-dismiss-notification]', list).forEach((button) => {
                button.addEventListener('click', () => dismissNotification(button));
            });
        });
    }

    async function dismissNotification(button) {
        const notificationId = button.dataset.dismissNotification;
        const item = button.closest('[data-notification-id]');

        if (!notificationId) {
            return;
        }

        button.disabled = true;
        item?.classList.add('is-removing');

        try {
            await api(`/notifications/${encodeURIComponent(notificationId)}`, { method: 'DELETE' });
            await refreshNotifications();
        } catch (error) {
            item?.classList.remove('is-removing');
            button.disabled = false;
            showToast(error.message, 'error');
        }
    }

    function notificationIcon(type) {
        if (['rejected', 'expired', 'exemption_rejected'].includes(type)) {
            return '!';
        }

        if (['approved', 'exemption_approved'].includes(type)) {
            return 'OK';
        }

        if (type === 'exemption_pending') {
            return 'EX';
        }

        return 'i';
    }

    async function refreshDashboardPage(options = {}) {
        const container = qs('[data-company-documents]');
        const summary = qs('[data-dashboard-summary]');
        const expiring = qs('[data-expiring-documents]');
        const silent = Boolean(options.silent);

        if (silent && shouldSkipSilentRefresh()) {
            return;
        }

        if (!silent) {
            container.innerHTML = '<div class="spinner">Caricamento...</div>';
            summary.innerHTML = '<div class="summary-skeleton">Caricamento...</div>';
            expiring.innerHTML = '<div class="spinner small">Caricamento...</div>';
        }

        const [dashboard, documents] = await Promise.all([
            api('/dashboard'),
            api('/company/documents'),
        ]);

        appState.companyDocuments = documents.documents;
        renderDashboardSummary(dashboard.summary, { silent });
        renderExpiringDocuments(dashboard.expiring_documents);
        renderCompanyDocuments({ silent });
    }

    function renderDashboardSummary(summary, options = {}) {
        const container = qs('[data-dashboard-summary]');

        if (!container) {
            return;
        }

        const cards = [
            ['Da caricare', summary.missing, 'missing', 'missing'],
            ['In attesa', summary.pending, 'pending', 'pending'],
            ['Approvati', summary.approved, 'approved', 'approved'],
            ['Respinti', summary.rejected, 'rejected', 'rejected'],
            ['Scaduti', summary.expired || 0, 'expired', 'expired'],
            ['In scadenza', summary.expiring, 'warning', 'expiring'],
        ];

        container.innerHTML = cards.map(([label, value, tone, filter]) => `
            <button class="summary-card ${tone}" type="button" data-summary-filter="${escapeHtml(filter)}" aria-pressed="${appState.documentFilter === filter}">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(value)}</strong>
            </button>
        `).join('');

        qsa('[data-summary-filter]', container).forEach((button) => {
            button.addEventListener('click', () => {
                setDocumentFilter(button.dataset.summaryFilter || 'all');
            });
        });

        setDocumentFilter(appState.documentFilter, { silent: Boolean(options.silent) });
    }

    function renderExpiringDocuments(documents) {
        const container = qs('[data-expiring-documents]');

        if (!container) {
            return;
        }

        if (!documents.length) {
            container.innerHTML = '<div class="empty-state compact">Nessun documento in scadenza nei prossimi 60 giorni.</div>';
            return;
        }

        container.innerHTML = documents.map((document) => {
            const info = expiryInfo(document.expiry_date);

            return `
                <article class="deadline-card">
                    <span class="entity-icon small">${svg('clock')}</span>
                    <span>
                        <strong>${escapeHtml(document.template)}</strong>
                        <span class="meta">${escapeHtml(document.section)} &middot; ${escapeHtml(document.owner)}${document.label ? ` &middot; ${escapeHtml(document.label)}` : ''}</span>
                    </span>
                    <span class="status expiry ${escapeHtml(info?.tone || 'notice')}">${escapeHtml(info?.label || 'Scadenza impostata')}</span>
                </article>
            `;
        }).join('');
    }

    function renderCompanyDocuments(options = {}) {
        const container = qs('[data-company-documents]');

        if (!container) {
            return;
        }

        const documents = filteredDocuments(appState.companyDocuments, appState.documentFilter);

        renderDocuments(container, documents, {
            type: 'company',
            refresh: refreshDashboardPage,
            emptyMessage: 'Nessun documento trovato con questo filtro.',
            silent: options.silent,
        });
    }

    function filteredDocuments(documents, filter) {
        if (filter === 'all') {
            return documents;
        }

        if (filter === 'expiring') {
            return documents.filter((item) => {
                const uploaded = item.uploaded_document;
                const info = expiryInfo(uploaded?.expiry_date);
                const internalInfo = expiryInfo(uploaded?.internal_expiry_date);

                return item.status === 'approved' && ((info && info.days >= 0 && info.days <= 60) || (internalInfo && internalInfo.days >= 0 && internalInfo.days <= 60));
            });
        }

        if (filter === 'missing') {
            return documents.filter((item) => ['missing', 'expired'].includes(item.status || 'missing')
                || (item.subdocuments || []).some((subitem) => ['missing', 'expired'].includes(subitem.status || 'missing')));
        }

        return documents.filter((item) => (item.status || 'missing') === filter
            || (item.subdocuments || []).some((subitem) => (subitem.status || 'missing') === filter));
    }

    async function initEmployees() {
        if (!requireAuth()) {
            return;
        }

        await loadMe();
        bindCompanyChrome();
        bindEntityForm('/employees', '[data-employee-form]', refreshEmployees);
        qs('[data-employee-search]')?.addEventListener('input', renderEmployees);
        await refreshEmployees();
        startLiveRefresh('employees', refreshEmployees);
    }

    async function refreshEmployees(options = {}) {
        const container = qs('[data-employees]');
        const silent = Boolean(options.silent);

        if (silent && shouldSkipSilentRefresh()) {
            return;
        }

        if (!silent) {
            container.innerHTML = '<div class="spinner">Caricamento...</div>';
        }

        const data = await api('/employees');
        appState.employees = data.employees;

        renderEmployees();
    }

    function renderEmployees() {
        const container = qs('[data-employees]');
        const search = (qs('[data-employee-search]')?.value || '').trim().toLowerCase();
        const employees = appState.employees.filter((employee) => `${employee.first_name} ${employee.last_name}`.toLowerCase().includes(search));

        if (!employees.length) {
            container.innerHTML = '<div class="empty-state">Nessun dipendente presente.</div>';
            return;
        }

        container.innerHTML = employees.map((employee) => {
            const progress = documentsProgress(employee);

            return `
            <button class="entity-card rich" type="button" data-open-documents="employee" data-id="${employee.id}" data-title="${escapeHtml(`${employee.first_name} ${employee.last_name}`)}" data-subtitle="${escapeHtml(progress.label)}">
                <span class="entity-icon">${svg('user')}</span>
                <span class="entity-main">
                    <span class="entity-title">${escapeHtml(employee.first_name)} ${escapeHtml(employee.last_name)}</span>
                    <span class="meta">${escapeHtml(progress.label)}</span>
                    <span class="progress-line"><span style="width: ${progress.percent}%"></span></span>
                </span>
                ${statusPill(progressStatus(employee))}
            </button>
        `;
        }).join('');

        bindDocumentButtons();
    }

    async function initVehicles() {
        if (!requireAuth()) {
            return;
        }

        await loadMe();
        bindCompanyChrome();
        bindEntityForm('/vehicles', '[data-vehicle-form]', refreshVehicles);
        qs('[data-vehicle-search]')?.addEventListener('input', renderVehicles);
        await refreshVehicles();
        startLiveRefresh('vehicles', refreshVehicles);
    }

    async function refreshVehicles(options = {}) {
        const container = qs('[data-vehicles]');
        const silent = Boolean(options.silent);

        if (silent && shouldSkipSilentRefresh()) {
            return;
        }

        if (!silent) {
            container.innerHTML = '<div class="spinner">Caricamento...</div>';
        }

        const data = await api('/vehicles');
        appState.vehicles = data.vehicles;

        renderVehicles();
    }

    function renderVehicles() {
        const container = qs('[data-vehicles]');
        const search = (qs('[data-vehicle-search]')?.value || '').trim().toLowerCase();
        const vehicles = appState.vehicles.filter((vehicle) => `${vehicle.plate} ${vehicle.capacity}`.toLowerCase().includes(search));

        if (!vehicles.length) {
            container.innerHTML = '<div class="empty-state">Nessun veicolo presente.</div>';
            return;
        }

        container.innerHTML = vehicles.map((vehicle) => {
            const progress = documentsProgress(vehicle);

            return `
            <button class="entity-card rich" type="button" data-open-documents="vehicle" data-id="${vehicle.id}" data-title="${escapeHtml(vehicle.plate)}" data-subtitle="${escapeHtml(progress.label)}">
                <span class="entity-icon">${svg('truck')}</span>
                <span class="entity-main">
                    <span class="entity-title">${escapeHtml(vehicle.plate)}</span>
                    <span class="meta">${escapeHtml(vehicle.capacity)} posti &middot; ${escapeHtml(progress.label)}</span>
                    <span class="progress-line"><span style="width: ${progress.percent}%"></span></span>
                </span>
                ${statusPill(progressStatus(vehicle))}
            </button>
        `;
        }).join('');

        bindDocumentButtons();
    }

    function bindEntityForm(endpoint, selector, refresh) {
        const form = qs(selector);

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideAlert();

            const button = qs('button[type="submit"]', form);
            setButtonLoading(button, true, 'Salvataggio...');

            try {
                await api(endpoint, {
                    method: 'POST',
                    body: Object.fromEntries(new FormData(form)),
                });
                form.reset();
                showAlert('Elemento aggiunto.', 'success');
                await refresh();
            } catch (error) {
                showAlert(error.message);
            } finally {
                setButtonLoading(button, false);
            }
        });
    }

    function bindDocumentButtons() {
        qsa('[data-open-documents]').forEach((button) => {
            button.addEventListener('click', () => {
                const type = button.dataset.openDocuments;
                const id = button.dataset.id;
                const endpoint = type === 'employee'
                    ? `/employees/${id}/documents`
                    : `/vehicles/${id}/documents`;
                const entity = type === 'employee'
                    ? appState.employees.find((employee) => String(employee.id) === String(id))
                    : appState.vehicles.find((vehicle) => String(vehicle.id) === String(id));

                openDocumentsModal({
                    title: button.dataset.title,
                    subtitle: button.dataset.subtitle,
                    endpoint,
                    type,
                    id,
                    entity,
                });
            });
        });
    }

    function syncEntityProgress(type, id, documents) {
        if (!['employee', 'vehicle'].includes(type)) {
            return null;
        }

        const loaded = documents.filter((item) => item.uploaded_document && item.status !== 'expired').length;
        const approved = documents.filter((item) => item.status === 'approved').length;
        const pending = documents.filter((item) => item.status === 'pending').length;
        const rejected = documents.filter((item) => item.status === 'rejected').length;
        const total = documents.length;
        const collectionKey = type === 'employee' ? 'employees' : 'vehicles';
        let updatedEntity = null;

        appState[collectionKey] = appState[collectionKey].map((entity) => {
            if (String(entity.id) !== String(id)) {
                return entity;
            }

            updatedEntity = {
                ...entity,
                documents_count: loaded,
                approved_documents_count: approved,
                pending_documents_count: pending,
                rejected_documents_count: rejected,
                required_documents_count: total,
            };

            return updatedEntity;
        });

        if (type === 'employee') {
            renderEmployees();
        } else {
            renderVehicles();
        }

        return updatedEntity;
    }

    function bindModalTabs(scope) {
        const buttons = qsa('[data-modal-tab]', scope);

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.dataset.modalTab;

                buttons.forEach((item) => {
                    item.classList.toggle('is-active', item === button);
                });

                qsa('[data-modal-panel]', scope).forEach((panel) => {
                    panel.classList.toggle('is-active', panel.dataset.modalPanel === target);
                });
            });
        });
    }

    function entityEditForm(options) {
        if (!options.entity) {
            return '';
        }

        if (options.type === 'employee') {
            return `
                <form class="modal-edit-card" data-entity-edit-form data-type="employee" data-id="${escapeHtml(options.id)}">
                    <div class="modal-edit-title">
                        <span class="entity-icon small">${svg('user')}</span>
                        <strong>Dettagli dipendente</strong>
                    </div>
                    <div class="modal-edit-grid">
                        <div class="field">
                            <label>Nome</label>
                            <input class="input" name="first_name" type="text" value="${escapeHtml(options.entity.first_name)}" required>
                        </div>
                        <div class="field">
                            <label>Cognome</label>
                            <input class="input" name="last_name" type="text" value="${escapeHtml(options.entity.last_name)}" required>
                        </div>
                        <div class="field">
                            <label>Telefono</label>
                            <input class="input" name="phone" type="tel" value="${escapeHtml(options.entity.phone)}">
                        </div>
                        <button class="btn secondary" type="submit" data-loading-text="Salvataggio..."><span class="btn-content">Salva</span><span class="btn-loader" aria-hidden="true"></span></button>
                    </div>
                </form>
            `;
        }

        return `
            <form class="modal-edit-card" data-entity-edit-form data-type="vehicle" data-id="${escapeHtml(options.id)}">
                <div class="modal-edit-title">
                    <span class="entity-icon small">${svg('truck')}</span>
                    <strong>Dettagli veicolo</strong>
                </div>
                <div class="modal-edit-grid">
                    <div class="field">
                        <label>Targa</label>
                        <input class="input" name="plate" type="text" value="${escapeHtml(options.entity.plate)}" required>
                    </div>
                    <div class="field">
                        <label>Capienza</label>
                        <select class="input" name="capacity" required>
                            ${[7, 10, 12, 13, 15, 17, 18, 21, 23, 32].map((capacity) => `<option value="${capacity}" ${Number(options.entity.capacity) === capacity ? 'selected' : ''}>${capacity} posti</option>`).join('')}
                        </select>
                    </div>
                    <button class="btn secondary" type="submit" data-loading-text="Salvataggio..."><span class="btn-content">Salva</span><span class="btn-loader" aria-hidden="true"></span></button>
                </div>
            </form>
        `;
    }

    function bindEntityEditForm(options, refreshModal) {
        const form = qs('[data-entity-edit-form]');

        if (!form) {
            return;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideAlert();

            const button = qs('button[type="submit"]', form);
            const endpoint = options.type === 'employee'
                ? `/employees/${options.id}`
                : `/vehicles/${options.id}`;

            setButtonLoading(button, true, 'Salvataggio...');

            try {
                const data = await api(endpoint, {
                    method: 'PUT',
                    body: Object.fromEntries(new FormData(form)),
                });

                if (options.type === 'employee') {
                    appState.employees = appState.employees.map((employee) => employee.id === data.employee.id ? data.employee : employee);
                    renderEmployees();
                    options.entity = data.employee;
                    options.title = `${data.employee.first_name} ${data.employee.last_name}`;
                } else {
                    appState.vehicles = appState.vehicles.map((vehicle) => vehicle.id === data.vehicle.id ? data.vehicle : vehicle);
                    renderVehicles();
                    options.entity = data.vehicle;
                    options.title = data.vehicle.plate;
                }

                qs('[data-modal-title]').textContent = options.title;
                showAlert('Modifica salvata.', 'success');
                await refreshModal();
            } catch (error) {
                showAlert(error.message);
            } finally {
                setButtonLoading(button, false);
            }
        });
    }

    async function openDocumentsModal(options) {
        const backdrop = qs('[data-modal]');
        const title = qs('[data-modal-title]');
        const subtitle = qs('[data-modal-subtitle]');
        const body = qs('[data-modal-body]');

        title.textContent = options.title;
        subtitle.textContent = options.subtitle;
        body.innerHTML = `
            <div class="modal-tabs">
                <button class="modal-tab is-active" type="button" data-modal-tab="documents">Documenti</button>
                <button class="modal-tab" type="button" data-modal-tab="details">Dettagli</button>
            </div>
            <div class="modal-panel is-active" data-modal-panel="documents">
                <div class="document-list" data-modal-documents>
                    ${documentCache.has(options.endpoint) ? '' : '<div class="spinner">Caricamento...</div>'}
                </div>
            </div>
            <div class="modal-panel" data-modal-panel="details">
                ${entityEditForm(options)}
            </div>
        `;
        backdrop.classList.add('is-open');
        document.body.classList.add('has-open-modal');
        bindModalTabs(body);

        const refresh = async () => {
            const data = await api(options.endpoint);
            documentCache.set(options.endpoint, data.documents);
            const updatedEntity = syncEntityProgress(options.type, options.id, data.documents);

            if (updatedEntity) {
                options.entity = updatedEntity;
                options.subtitle = documentsProgress(updatedEntity).label;
                subtitle.textContent = options.subtitle;
            }

            renderDocuments(qs('[data-modal-documents]', body), data.documents, {
                type: options.type,
                id: options.id,
                endpoint: options.endpoint,
                refresh,
                silent: true,
            });
        };

        bindEntityEditForm(options, refresh);

        try {
            if (documentCache.has(options.endpoint)) {
                renderDocuments(qs('[data-modal-documents]', body), documentCache.get(options.endpoint), {
                    type: options.type,
                    id: options.id,
                    endpoint: options.endpoint,
                    refresh,
                });
            }

            await refresh();
        } catch (error) {
            qs('[data-modal-documents]', body).innerHTML = `<div class="empty-state">${escapeHtml(error.message)}</div>`;
        }
    }

    function bindModal() {
        const backdrop = qs('[data-modal]');

        if (!backdrop) {
            return;
        }

        qsa('[data-close-modal]').forEach((button) => {
            button.addEventListener('click', () => {
                backdrop.classList.remove('is-open');
                document.body.classList.remove('has-open-modal');
            });
        });

        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) {
                backdrop.classList.remove('is-open');
                document.body.classList.remove('has-open-modal');
            }
        });
    }

    function bindLogout() {
        qsa('[data-logout]').forEach((button) => {
            button.addEventListener('click', async () => {
                setButtonLoading(button, true, 'Uscita...');

                try {
                    await api('/logout', { method: 'POST' });
                } finally {
                    clearAuth();
                    window.location.href = 'login.html';
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const page = document.body.dataset.page;

        bindModal();
        bindPasswordToggles();
        bindButtonAnimations();

        if (page === 'login') {
            initLogin();
        }

        if (page === 'register') {
            initRegister();
        }

        if (page === 'dashboard') {
            initDashboard();
        }

        if (page === 'employees') {
            initEmployees();
        }

        if (page === 'vehicles') {
            initVehicles();
        }
    });
})();
