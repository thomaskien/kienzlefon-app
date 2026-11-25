/* Browser client for the same-origin telephony proxy. */
(() => {
    'use strict';
    const endpoint = 'telepraxis-telephony.php';
    const groups = { normal: 'Normal', specialist: 'ROT' };
    const states = { idle: 'Frei', ringing: 'Klingelt', busy: 'Belegt', offline: 'Nicht erreichbar', unknown: '—' };
    const reasons = {
        not_supported: 'Funktion nicht verfügbar.', state_unknown: 'Zustand unbekannt.',
        route_unavailable: 'Rufgruppe nicht verfügbar.', global_disabled: 'Fernbedienung deaktiviert.',
        not_eligible: 'Nebenstelle für diese Gruppe nicht freigegeben.', admin_locked: 'Durch Administration gesperrt.',
        last_member: 'Das letzte Mitglied der Gruppe bleibt eingeschaltet.',
    };
    const store = 'kienzlefon_pending_telephony_v1';
    const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    const berlinDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin',
        year: 'numeric', month: '2-digit', day: '2-digit' });
    const safe = text => String(text ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[character]);

    function uuid() {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    async function request(action, options = {}) {
        const query = new URLSearchParams({ action });
        if (action === 'bootstrap' || action === 'status') query.set('display', '1');
        if (options.id) query.set('request_id', options.id);
        const controller = new AbortController();
        const time = setTimeout(() => controller.abort(), 11000);
        try {
            const init = { signal: controller.signal, cache: 'no-store', credentials: 'same-origin' };
            if (options.command) Object.assign(init, {
                method: 'POST', body: JSON.stringify(options.command),
                headers: { 'Content-Type': 'application/json', 'X-Kienzlefon-CSRF': options.csrf },
            });
            const response = await fetch(`${endpoint}?${query}`, init);
            return { status: response.status, ok: response.ok, data: await response.json() };
        } finally {
            clearTimeout(time);
        }
    }

    function readPending() {
        try {
            const items = JSON.parse(sessionStorage.getItem(store) || '[]');
            return new Map(items.filter(item => uuidPattern.test(item.id) && /^\d{1,12}$/.test(item.extension)
                && Object.hasOwn(groups, item.route) && typeof item.previous === 'boolean'
                && typeof item.expires === 'string' && typeof item.started === 'number')
                .map(item => [`${item.extension}/${item.route}`, { ...item, sending: false }]));
        } catch {
            return new Map();
        }
    }

    class Telephony {
        constructor(toggle, panel, workplace, changed, notify, patientButtons) {
            this.toggle = toggle;
            this.panel = panel;
            this.list = panel.querySelector('[data-nst-list]');
            this.workplace = workplace;
            this.changed = changed;
            this.notify = notify;
            this.patientButtons = patientButtons;
            this.calls = document.getElementById('telephony-calls');
            this.calls.addEventListener('change', event => {
                if (event.target.matches('select[data-target]')) { this.target = event.target.value; this.paintCalls(); }
            });
            this.calls.addEventListener('click', event => {
                const button = event.target.closest('button');
                if (!button) return;
                const {menu, mode, control, job} = button.dataset;
                if (menu) {
                    this.menuCall = this.menuCall === menu ? '' : menu;
                    this.target = '';
                    this.paintCalls();
                    this.calls.querySelector('select')?.focus();
                } else if (mode && this.target && this.menuCall) {
                    this.action('transfer', {call_id:this.menuCall, target_type:'internal', target:this.target, mode});
                } else if (control) this.action(control, {transfer_id:job});
            });
            document.addEventListener('keydown', event => this.keyboard(event));
            document.addEventListener('focusin', event => {
                if (this.shortcuts && !this.panel.contains(event.target)) { this.shortcuts = null; this.paint(); }
            });
            this.list.addEventListener('click', event => {
                const target = event.target.closest('[data-dial]');
                if (target) this.dial(target.dataset.dial);
            });
            this.pending = readPending();
            this.generation = 0;
            this.overview = null;
            this.at = 0;
            this.csrf = null;
            this.clients = [];
            this.lastSummary = '';
            const preference = localStorage.getItem('kienzlefon_telephony_enabled');
            toggle.checked = preference === '1';
            toggle.addEventListener('change', () => {
                localStorage.setItem('kienzlefon_telephony_enabled', toggle.checked ? '1' : '0');
                this.restart();
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && this.toggle.checked) this.restart();
            });
            panel.addEventListener('toggle', () => { if (!panel.open) this.shortcuts = null; this.paint(); });
            this.list.addEventListener('change', event => {
                const input = event.target.closest('input[data-route]');
                if (input) this.submit(input.dataset.extension, input.dataset.route);
            });
            setInterval(() => {
                if (this.toggle.checked) this.paint();
            }, 1000);
            if (preference === null) this.detect();
        }

        keyboard(event) {
            if (event.repeat || event.defaultPrevented || event.isComposing || event.altKey || event.metaKey || event.ctrlKey
                || event.target.closest?.('input,textarea,select,[contenteditable]:not([contenteditable="false"]),[role="textbox"],dialog[open]')) return;
            if (!this.toggle.checked) return;
            if (event.key.toLowerCase() === 't') {
                event.preventDefault();
                this.panel.open = !this.panel.open;
                this.shortcuts = null;
                if (this.panel.open && this.workplaceActions && this.workplace() && this.overview?.extensions.length) {
                    this.shortcuts = this.overview.extensions.map(ext => ext.id);
                }
                this.shortcutPage = 0;
                this.panel.querySelector('summary').focus();
                this.paint();
                return;
            }
            if (!this.shortcuts || !this.panel.open || !this.workplaceActions || !this.workplace()) return;
            if (event.key === 'Escape' || event.key === 'Tab') { this.shortcuts = null; this.paint(); return; }
            if (!/^\d$/.test(event.key)) return;
            event.preventDefault();
            if (event.key === '0') {
                this.shortcutPage = (this.shortcutPage + 1) % Math.ceil(this.shortcuts.length / 9);
                this.paint();
                return;
            }
            const id = this.shortcuts[this.shortcutPage * 9 + Number(event.key) - 1];
            if (id && this.overview?.extensions.some(ext => ext.id === id)) this.dial(id);
        }

        async action(action, fields) {
            const client = this.workplace();
            if (!client) return this.notify('Bitte zuerst das eigene Telefon eintragen und speichern.', true);
            if (this.working) return;
            this.working = true;
            try {
                if (!this.csrf) {
                    const boot = await request('bootstrap');
                    if (!boot.ok) throw new Error('Telefonie nicht eingerichtet.');
                    this.csrf = boot.data.csrf_token;
                }
                const response = await request(action, {csrf: this.csrf, command: {...fields, client_id: client, request_id: uuid()}});
                if (!response.ok) {
                    if (response.data.error?.code === 'csrf_failed') this.csrf = null;
                    throw new Error(response.data.error?.message || 'Auftrag fehlgeschlagen.');
                }
                if (['unknown','failed'].includes(response.data.phase)) {
                    throw new Error(response.data.message || 'Wählauftrag nicht bestätigt. Bitte das Telefon prüfen.');
                }
                this.notify(response.data.message || (action === 'dial' ? 'Wählauftrag übergeben.' : 'Auftrag übergeben.'));
                if (action === 'transfer') { this.menuCall = ''; this.target = ''; }
            } catch (error) {
                this.notify(error.name === 'AbortError' ? 'Ergebnis ungewiss. Bitte am Telefon prüfen.' : error.message, true);
            } finally {
                this.working = false;
                this.restart();
            }
        }

        dial(number) {
            this.shortcuts = null;
            this.paint();
            return this.action('dial', {number});
        }

        async getDesk() {
            const client_id = this.workplace();
            if (!this.workplaceActions || !client_id) return null;
            try {
                const response = await request('desk', {csrf: this.csrf, command: {client_id}});
                return response.ok && response.data.client_id === this.workplace() ? response.data : null;
            } catch { return null; }
        }

        paintCalls() {
            const desk = this.toggle.checked && performance.now() - this.deskAt < 10000 && this.desk?.client_id === this.workplace() ? this.desk : null;
            const calls = desk?.calls || [];
            const jobs = desk?.transfers || [];
            if (!calls.some(c => c.id === this.menuCall && c.can_transfer)) { this.menuCall = ''; this.target = ''; }
            const html = calls.map(call => {
                const menu = this.menuCall === call.id;
                const targets = (this.overview?.extensions || []).filter(ext => ext.id !== desk.extension);
                const control = call.can_transfer ? `<button type="button" data-menu="${safe(call.id)}" aria-expanded="${menu}">Vermitteln</button>` : '';
                const choices = menu ? `<div class="transfer-options"><label>Ziel <select data-target><option value="">Nebenstelle wählen</option>${targets.map(e =>
                    `<option value="${safe(e.id)}"${this.target === e.id ? ' selected' : ''}>${safe(e.id)} · ${safe(e.label)}</option>`).join('')}</select></label>
                    ${[['blind','Direkt durchstellen'], ['attended','Rückfrage starten']].map(([mode, label]) =>
                        `<button type="button" data-mode="${mode}" ${!targets.some(e => e.id === this.target) ? 'disabled' : ''}>${label}</button>`).join('')}</div>` : '';
                const names = this.patientButtons?.(call.treffer, call.number, ' oder ') || safe(call.name);
                const number = safe(call.number);
                const phone = number ? `<span class="call-number"><span class="phone-digits">${names ? '(' + number + ')' : number}</span></span>` : '';
                const caller = [names, phone].filter(Boolean).join(' ') || 'Unbekannter Anrufer';
                return `<div data-call-id="${safe(call.id)}" class="call-bubble ${call.state === 'connected' ? 'connected' : 'ringing'}"><b>${call.state === 'connected' ? 'Im Gespräch' : 'Klingelt'}</b> ${caller} ${control}${choices}</div>`;
            }).concat(jobs.map(job => `<div class="call-bubble connected"><b>${job.mode === 'attended' ? 'Rückfrage mit' : 'Vermittlung an'} ${safe(job.target)}</b>
                ${safe(job.message)} ${[['complete','Gespräch übergeben'],['cancel','Zurück zum Anrufer']].map(([key,label]) => job['can_'+key] ?
                    `<button type="button" data-control="${key}" data-job="${safe(job.request_id)}">${label}</button>` : '').join('')}</div>`)).join('');
            if (html === this.callMarkup) return;
            const active = this.calls.contains(document.activeElement) ? document.activeElement : null;
            this.calls.innerHTML = html;
            this.callMarkup = html;
            if (active) {
                const same = [...this.calls.querySelectorAll('button,select')].find(el =>
                    el.closest('[data-call-id]')?.dataset.callId === active.closest('[data-call-id]')?.dataset.callId
                    && JSON.stringify(el.dataset) === JSON.stringify(active.dataset));
                (same || this.panel.querySelector('summary')).focus({preventScroll:true});
            }
        }

        async detect() {
            try {
                const boot = await request('bootstrap');
                if (localStorage.getItem('kienzlefon_telephony_enabled') !== null
                    || !boot.ok || boot.data?.schema_version !== 1 || !Array.isArray(boot.data.clients)
                    || !boot.data.csrf_token) return;
                this.csrf = boot.data.csrf_token;
                this.clients = boot.data.clients;
                this.workplaceActions = boot.data.workplace_actions === true;
                this.toggle.checked = true;
                this.restart();
            } catch {
                // Die optionale Telefonie kann auf diesem App-Server fehlen.
            }
        }

        restart() {
            clearTimeout(this.timer);
            const generation = ++this.generation;
            if (!this.toggle.checked) this.overview = null;
            this.paint();
            if (this.toggle.checked) this.poll(generation);
        }

        fresh(age) {
            return Number.isInteger(age) && age + performance.now() - this.at < 10000;
        }

        async refreshRoutingAccess(generation) {
            if (this.accessGeneration === generation) return;
            if (this.routingAccess && performance.now() - this.accessAt < 3500) return;
            this.accessGeneration = generation;
            try {
                const result = await request('routing-access');
                if (generation !== this.generation) return;
                this.routingAccess = result.ok ? result.data : null;
                this.accessAt = performance.now();
                if (result.data?.error?.code === 'csrf_failed') this.csrf = null;
            } catch {
                if (generation === this.generation) this.routingAccess = null;
            } finally {
                if (generation === this.generation) {
                    this.accessGeneration = null;
                    this.paint();
                }
            }
        }

        summary() {
            if (!this.toggle.checked) return [];
            const live = this.overview?.live_counters;
            const current = live?.status === 'current' && this.fresh(live.age_ms);
            const stats = this.overview?.call_statistics?.today;
            const parts = Object.fromEntries(berlinDate.formatToParts(new Date()).map(({type, value}) => [type, value]));
            const date = `${parts.year}-${parts.month}-${parts.day}`;
            const hasCount = stats?.date === date && Number.isInteger(stats.incoming_calls) && stats.status !== 'unavailable';
            const count = hasCount ? stats.incoming_calls : null;
            const hint = hasCount ? [
                ...(stats.routes || []).map(route => `${route.label}: ${route.incoming_calls}`),
                `Ohne erfassten Weg: ${stats.unclassified_calls}`, 'Mehrere Wege pro Anruf möglich.',
            ].join('\n') : 'Tageszählung nicht verfügbar';
            return [
                ['warte', 'Warteschleife', current ? live.waiting : '—'],
                ['ivr', 'IVR', current ? live.ivr : '—'],
                ['ai', 'AI', current && this.overview.ai_enabled === true ? live.ai : null],
                ['heute', 'Anrufe heute', count, hint],
            ].filter(([, , value]) => Number.isInteger(value));
        }

        permitted(ext, route) {
            const routing = this.overview?.routing_control;
            const group = routing?.groups.find(group => group.id === route);
            const member = ext.routing[route];
            const permission = ext.routing.permissions[route];
            if (!routing?.available || !this.fresh(routing.age_ms) || typeof member !== 'boolean') {
                return { member: null, hint: reasons.state_unknown };
            }
            if (!routing.enabled || this.overview.source !== 'live') return { member, hint: reasons.global_disabled };
            if (!group?.available) return { member, hint: reasons.route_unavailable };
            const access = this.routingAccess;
            if (!access || performance.now() - this.accessAt >= 10000) {
                return { member, hint: 'Freigabe für Rufgruppen derzeit nicht verfügbar.' };
            }
            const clientId = access.legacy === true ? this.clients.find(item => item.id === this.workplace())?.id : access.client_id;
            if (!clientId) return { member, hint: 'Für diese Sitzung fehlt die Freigabe zum Ändern der Rufgruppen.' };
            const allowed = member ? permission.can_disable : permission.can_enable;
            const reason = member ? permission.disable_reason : permission.enable_reason;
            return { member, clientId, hint: allowed ? '' : reasons[reason] || 'Änderung gesperrt.' };
        }

        paint() {
            this.panel.hidden = !this.toggle.checked;
            this.paintCalls();
            const summary = JSON.stringify(this.summary());
            if (summary !== this.lastSummary) {
                this.lastSummary = summary;
                this.changed();
            }
            if (this.panel.hidden || !this.panel.open) return;
            const extensions = this.overview?.extensions || [];
            const content = extensions.length ? extensions.map(ext => {
                const state = this.fresh(ext.state_age_ms) ? states[ext.state] : states.unknown;
                const boxes = Object.entries(groups).map(([route, label]) => {
                    const current = this.permitted(ext, route);
                    const pending = this.pending.get(`${ext.id}/${route}`);
                    const member = pending ? pending.previous : current.member;
                    const digit = this.overview.routing_control.groups.find(item => item.id === route)?.ivr_digit;
                    const progress = Date.now() - (pending?.started || 0) > 30000 ? 'Ausgang wird geprüft' : 'Wird geändert';
                    const hint = pending ? progress : current.hint || (digit ? `IVR-Taste ${digit}` : '');
                    return `<label title="${safe(hint)}"><input type="checkbox" aria-label="${safe(label + ' ' + ext.id)}"
                    data-extension="${safe(ext.id)}" data-route="${route}"${member === true ? ' checked' : ''}
                    ${pending || current.hint ? 'disabled' : ''}>${label}${member === null ? ' —' : ''}${pending ? ` <small>${progress}</small>` : ''}</label>`;
                }).join('');
                const key = this.shortcuts?.slice(this.shortcutPage * 9, this.shortcutPage * 9 + 9).indexOf(ext.id) ?? -1;
                const heading = `<strong>${safe(ext.label)}</strong><span class="extension-state"><span class="${state === states.idle ? 'idle' : state === states.busy ? 'busy' : ''}">${safe(state)}</span><strong class="telephony-number">${safe(ext.id)}</strong></span>`;
                const contact = this.workplaceActions
                    ? `<button type="button" class="extension-contact" data-dial="${safe(ext.id)}" aria-label="Nebenstelle ${safe(ext.id)} anrufen">${heading}</button>`
                    : heading;
                return `<div class="telephony-extension">${key >= 0 ? `<kbd>${key+1}</kbd>` : ''}${contact}
                    <div class="telephony-groups">${boxes}</div></div>`;
            }).join('') : '<span class="telephony-unavailable">Nebenstellenstatus nicht verfügbar.</span>';
            this.panel.querySelector('[data-shortcut-help]').textContent = this.shortcuts?.length > 9 ? '0 · Weitere Nebenstellen' : '';
            if (this.lastMarkup !== content) {
                const focus = this.list.contains(document.activeElement) ? document.activeElement.dataset : null;
                this.list.innerHTML = content;
                this.lastMarkup = content;
                if (focus?.dial) this.list.querySelector(`[data-dial="${CSS.escape(focus.dial)}"]`)?.focus({preventScroll:true});
                else if (focus) this.list.querySelector(`input[data-extension="${CSS.escape(focus.extension || '')}"][data-route="${CSS.escape(focus.route || '')}"]`)?.focus({ preventScroll: true });
            }
        }

        save() {
            try {
                const entries = [...this.pending.values()].map(({ sending, ...item }) => item);
                sessionStorage.setItem(store, JSON.stringify(entries));
            } catch {
                // Keep checking open requests in this page even if storage is disabled.
            }
        }

        async poll(generation) {
            const current = () => generation === this.generation && this.toggle.checked;
            try {
                if (!this.csrf) {
                    const boot = await request('bootstrap');
                    if (!current()) return;
                    if (!boot.ok || boot.data.schema_version !== 1) throw new Error('Telefonie nicht eingerichtet.');
                    this.csrf = boot.data.csrf_token;
                    this.clients = boot.data.clients;
                    this.workplaceActions = boot.data.workplace_actions === true;
                }
                this.refreshRoutingAccess(generation);
                const now = Date.now();
                const checks = await Promise.all([...this.pending].map(async ([key, item]) => {
                    if (item.sending || now - (item.lastChecked || 0) < (now - item.started > 30000 ? 5000 : 1800)) return null;
                    item.lastChecked = now;
                    try {
                        return [key, item.id, await request('request', { id: item.id })];
                    } catch {
                        return null;
                    }
                }));
                if (!current()) return;
                const start = performance.now();
                const [result, desk] = await Promise.all([request('status'), this.getDesk()]);
                if (current()) { this.desk = desk; this.deskAt = start; }
                if (!current()) return;
                if (!result.ok || result.data.schema_version !== 1) throw new Error('Telefonieübersicht nicht verfügbar.');
                this.overview = result.data;
                this.at = start;
                for (const check of checks.filter(Boolean)) this.reconcile(...check);
                for (const [key, item] of this.pending) {
                    const routing = this.overview.routing_control;
                    if (item.applied && routing.available && this.fresh(routing.age_ms)
                        && (routing.pbx_epoch !== item.epoch
                            || BigInt(routing.revision.split(':').at(-1)) >= BigInt(item.applied.split(':').at(-1)))) {
                        this.pending.delete(key);
                    }
                }
                this.save();
            } catch {
                if (current()) { this.overview = null; this.desk = null; }
            } finally {
                if (current()) {
                    this.paint();
                    this.timer = setTimeout(() => this.poll(generation), document.hidden ? 15000 : 2000);
                }
            }
        }

        reconcile(key, id, result) {
            const item = this.pending.get(key);
            if (item?.id !== id) return;
            const job = result.data.request;
            if (job?.state === 'applied') {
                item.applied = job.result_revision;
            } else if (job?.state === 'rejected' || job?.state === 'expired'
                || (result.status === 404 && result.data.error?.code === 'request_not_found'
                    && Date.parse(this.overview?.generated_at) > Date.parse(item.expires) + 5000)) {
                this.pending.delete(key);
                this.notify(result.data.error?.message || 'Telefonie-Auftrag wurde nicht angewendet.', true);
            } else if (result.status === 403 && result.data.error?.code === 'client_forbidden'
                && this.overview?.routing_control.available && this.fresh(this.overview.routing_control.age_ms)
                && Date.parse(this.overview.generated_at) > Date.parse(item.expires) + 5000) {
                this.pending.delete(key);
                this.notify('Auftrag in dieser Sitzung nicht mehr abrufbar. Die Haken zeigen die aktuelle Teilnahme.', true);
            }
        }

        async submit(extension, route) {
            const key = `${extension}/${route}`;
            const ext = this.overview?.extensions.find(item => item.id === extension);
            if (!Object.hasOwn(groups, route) || !ext || this.pending.has(key)) return;
            const allowed = this.permitted(ext, route);
            const routing = this.overview.routing_control;
            if (allowed.hint || !this.csrf) {
                this.lastMarkup = '';
                this.paint();
                return;
            }
            const command = {
                request_id: uuid(), client_id: allowed.clientId, extension, route, member: !allowed.member,
                expected_revision: routing.revision, pbx_epoch: routing.pbx_epoch,
                expires_at: new Date(Date.parse(this.overview.generated_at) + 30000).toISOString(),
            };
            const item = { id: command.request_id, previous: allowed.member, extension, route,
                epoch: routing.pbx_epoch, expires: command.expires_at, started: Date.now(), sending: true };
            this.pending.set(key, item);
            this.save();
            this.paint();
            try {
                const result = await request('routing', { command, csrf: this.csrf });
                this.reconcile(key, item.id, result);
                const err = result.data.error;
                if (!result.ok && !result.data.request && result.status < 500) {
                    this.pending.delete(key);
                    if (err?.code === 'csrf_failed') this.csrf = null;
                    if (result.status === 403) this.routingAccess = null;
                    this.notify(err?.message || 'Telefonie-Auftrag wurde abgelehnt.', true);
                }
            } catch {
                // A lost POST response does not permit sending another command.
            } finally {
                item.sending = false;
                this.save();
                this.restart();
            }
        }
    }

    window.KienzlefonTelephony = Telephony;
})();
