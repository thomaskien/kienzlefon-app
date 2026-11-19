/* Browser client for the same-origin telephony proxy. */
(() => {
    'use strict';
    const endpoint = 'telepraxis-telephony.php';
    const groups = { normal: 'Normal', specialist: 'ROT' };
    const states = { idle: 'Frei', ringing: 'Klingelt', busy: 'Besetzt', offline: 'Nicht erreichbar', unknown: '—' };
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
        constructor(toggle, panel, workplace, changed, notify) {
            this.toggle = toggle;
            this.panel = panel;
            this.list = panel.querySelector('[data-nst-list]');
            this.workplace = workplace;
            this.changed = changed;
            this.notify = notify;
            this.pending = readPending();
            this.generation = 0;
            this.overview = null;
            this.at = 0;
            this.csrf = null;
            this.clients = [];
            this.lastSummary = '';
            toggle.checked = localStorage.getItem('kienzlefon_telephony_enabled') === '1';
            toggle.addEventListener('change', () => {
                localStorage.setItem('kienzlefon_telephony_enabled', toggle.checked ? '1' : '0');
                this.restart();
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && this.toggle.checked) this.restart();
            });
            panel.addEventListener('toggle', () => this.paint());
            this.list.addEventListener('change', event => {
                const input = event.target.closest('input[data-route]');
                if (input) this.submit(input.dataset.extension, input.dataset.route);
            });
            setInterval(() => {
                if (this.toggle.checked) this.paint();
            }, 1000);
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

        summary() {
            if (!this.toggle.checked) return [];
            const live = this.overview?.live_counters;
            const current = live?.status === 'current' && this.fresh(live.age_ms);
            const stats = this.overview?.call_statistics?.today;
            const parts = Object.fromEntries(berlinDate.formatToParts(new Date()).map(({type, value}) => [type, value]));
            const date = `${parts.year}-${parts.month}-${parts.day}`;
            const hasCount = stats?.date === date && Number.isInteger(stats.incoming_calls) && stats.status !== 'unavailable';
            const count = hasCount ? (stats.status === 'partial' ? '≈' : '') + stats.incoming_calls : '—';
            const hint = hasCount ? [
                ...(stats.status === 'partial' ? ['Ungefährer Wert; Erfassung nicht durchgehend.'] : []),
                ...(stats.routes || []).map(route => `${route.label}: ${route.incoming_calls}`),
                `Ohne erfassten Weg: ${stats.unclassified_calls}`, 'Mehrere Wege pro Anruf möglich.',
            ].join('\n') : 'Tageszählung nicht verfügbar';
            return [
                ['warte', 'Warteschleife', current ? live.waiting : '—'],
                ['ivr', 'IVR', current ? live.ivr : '—'],
                ['ai', 'AI', current ? live.ai : '—'],
                ['heute', 'Anrufe heute', count, hint],
            ];
        }

        permitted(ext, route) {
            const routing = this.overview?.routing_control;
            const group = routing?.groups.find(group => group.id === route);
            const member = ext.routing[route];
            const permission = ext.routing.permissions[route];
            const client = this.clients.find(item => item.id === this.workplace());
            if (!routing?.available || !this.fresh(routing.age_ms) || typeof member !== 'boolean') {
                return { member: null, hint: reasons.state_unknown };
            }
            if (!routing.enabled || this.overview.source !== 'live') return { member, hint: reasons.global_disabled };
            if (!group?.available) return { member, hint: reasons.route_unavailable };
            if (!client) return { member, hint: 'Diesen Arbeitsplatz in der Telefonie freigeben lassen.' };
            const allowed = member ? permission.can_disable : permission.can_enable;
            const reason = member ? permission.disable_reason : permission.enable_reason;
            return { member, client, hint: allowed ? '' : reasons[reason] || 'Änderung gesperrt.' };
        }

        paint() {
            this.panel.hidden = !this.toggle.checked;
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
                return `<div class="telephony-extension"><strong>${safe(ext.label)}</strong>
                    <div>${safe(state)} <span class="telephony-number">${safe(ext.id)}</span></div>
                    <div class="telephony-groups">${boxes}</div></div>`;
            }).join('') : '<span class="telephony-unavailable">Nebenstellenstatus nicht verfügbar.</span>';
            if (this.lastMarkup !== content) {
                const focus = this.list.contains(document.activeElement) ? document.activeElement.dataset : null;
                this.list.innerHTML = content;
                this.lastMarkup = content;
                if (focus) this.list.querySelector(`input[data-extension="${CSS.escape(focus.extension || '')}"][data-route="${CSS.escape(focus.route || '')}"]`)?.focus({ preventScroll: true });
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
                }
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
                const result = await request('status');
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
                if (current()) this.overview = null;
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
                request_id: uuid(), client_id: allowed.client.id, extension, route, member: !allowed.member,
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
