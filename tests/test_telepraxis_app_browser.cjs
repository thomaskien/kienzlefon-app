/* Real-browser checks against a temporary PHP site and synthetic middleware. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const net = require('node:net');
const http = require('node:http');
const { randomBytes } = require('node:crypto');

(async () => {
    const root = path.resolve(__dirname, '..');
    const site = fs.mkdtempSync(path.join(os.tmpdir(), 'kienzlefon-v36-'));
    const config = fs.mkdtempSync(path.join(os.tmpdir(), 'kienzlefon-telephony-test-'));
    let server, browser, middleware;
    try {
        fs.mkdirSync(path.join(site, 'inbox'));
        fs.mkdirSync(path.join(site, 'sessions'));
        for (const name of ['telepraxis-app.php', 'telepraxis-sms.php', 'telepraxis-sms-queue.php', 'telepraxis-telephony.js', 'telepraxis-telephony.php', 'telepraxis-telephony-schema.json']) {
            fs.copyFileSync(path.join(root, name), path.join(site, name));
        }
        let source = fs.readFileSync(path.join(site, 'telepraxis-app.php'), 'utf8');
        source = source.replace('const TELEPRAXIS_T2MED_ENABLED = false;', 'const TELEPRAXIS_T2MED_ENABLED = true;')
            .replace(/const TELEPRAXIS_ADMIN_PASSWORD = .*?;/, "const TELEPRAXIS_ADMIN_PASSWORD = 'integration-admin';")
            .replace("const TELEPRAXIS_T2MED_BASE_URL = '';", "const TELEPRAXIS_T2MED_BASE_URL = 'http://middleware.example:8765';")
            .replace("const TELEPRAXIS_RECORDINGS_BASE_URL = '';", "const TELEPRAXIS_RECORDINGS_BASE_URL = 'recordings';");
        fs.writeFileSync(path.join(site, 'telepraxis-app.php'), source);
        fs.writeFileSync(path.join(site, 'sms-credentials.json'), JSON.stringify({default_provider: 'none'}));
        const entry = {
            received_at: '2026-10-12T09:00:00+02:00',
            payload: {typ: 'rueckruf_details', vorname: 'Erika', nachname: 'Beispiel', telefon: '+493055501111', id: '+493055501112', grund: 'Bitte um Rückruf.', zusammenfassung: 'Synthetischer Testvorgang.'},
            app: {status: 'in_bearbeitung', status_updated_benutzer: 'Julia', status_updated_arbeitsplatz: 'anderer-platz', comments: []},
        };
        fs.writeFileSync(path.join(site, 'inbox', 'example.json'), JSON.stringify(entry));
        fs.writeFileSync(path.join(site, 'inbox', 'other.json'), JSON.stringify({...entry, app: {...entry.app, status_updated_benutzer: 'Anna', status_updated_arbeitsplatz: 'empfang-1'}}));
        const fixtures = JSON.parse(fs.readFileSync(path.join(root, 'docs/telefonie-v1/fixtures.json'))).fixtures;
        const fixture = name => structuredClone(fixtures.find(item => item.name === name).body);
        const token = randomBytes(32).toString('hex');
        const tokenFile = path.join(config, 'token');
        fs.writeFileSync(tokenFile, token, {mode: 0o600});
        let overviewName = 'overview-current';
        let commands = [], confirm = false, lookups = [];
        middleware = http.createServer(async (req, res) => {
            res.setHeader('Content-Type', 'application/json');
            if (req.headers.authorization !== `Bearer ${token}`) { res.writeHead(401).end('{}'); return; }
            if (req.url === '/api/v1/telephony/overview') {
                const overview = fixture(overviewName);
                overview.generated_at = new Date().toISOString();
                overview.call_statistics.today.date = new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
                if (confirm) {
                    overview.routing_control.revision = overview.routing_control.revision.replace(/:17$/, ':18');
                    overview.extensions[0].routing.normal = false;
                }
                overview.internal_channel = 'server-side-details';
                res.end(JSON.stringify(overview));
                return;
            }
            if (req.method === 'POST' && req.url === '/api/v1/extension-routing') {
                const chunks = [];
                for await (const chunk of req) chunks.push(chunk);
                const command = JSON.parse(Buffer.concat(chunks));
                commands.push(command);
                res.writeHead(202).end(JSON.stringify({request: {...fixture('request-accepted').request, ...command}, error: null}));
                return;
            }
            if (req.url === `/api/v1/extension-routing/requests/${commands[0]?.request_id}`) {
                const [command] = commands;
                lookups.push(req.url);
                res.end(JSON.stringify({request: {...fixture(confirm ? 'request-applied' : 'request-accepted').request, ...command}, error: null}));
                return;
            }
            res.writeHead(404).end('{}');
        });
        await new Promise((resolve, reject) => {middleware.once('error', reject); middleware.listen(0, '127.0.0.1', resolve);});
        const configFile = path.join(config, 'telephony-config.php');
        fs.writeFileSync(configFile, `<?php return ['enabled' => true,
            'middleware_url' => 'http://127.0.0.1:${middleware.address().port}',
            'token_file' => __DIR__ . '/token',
            'clients' => [['id' => 'empfang-1', 'label' => 'Empfang 1', 'extension' => '201']]];`);
        // Ask the OS for a loopback port, then start the temporary PHP server.
        const allocator = net.createServer();
        await new Promise((resolve, reject) => { allocator.once('error', reject); allocator.listen(0, '127.0.0.1', resolve); });
        const port = allocator.address().port;
        await new Promise(resolve => allocator.close(resolve));
        server = spawn('php', ['-d', 'opcache.enable=0', '-d', `session.save_path=${path.join(site, 'sessions')}`, '-S', `127.0.0.1:${port}`, '-t', site], {env: {...process.env, KIENZLEFON_TELEPHONY_CONFIG: configFile}, stdio: ['ignore', 'ignore', 'pipe']});
        let serverErrors = '';
        server.stderr.on('data', data => { serverErrors += data; });
        const base = `http://127.0.0.1:${port}/telepraxis-app.php`;
        for (let i = 0; i < 60; i++) {
            try { if ((await fetch(base)).ok) break; } catch {}
            if (i === 59) throw new Error(serverErrors);
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch(process.env.PLAYWRIGHT_CHANNEL ? {channel: process.env.PLAYWRIGHT_CHANNEL} : {});
        const context = await browser.newContext({viewport: {width: 1600, height: 1000}, permissions: ['clipboard-read', 'clipboard-write']});
        const errors = [], external = [];
        await context.route('**/*', route => {
            if (new URL(route.request().url()).hostname !== '127.0.0.1') {
                external.push(route.request().url());
                return route.abort();
            }
            return route.continue();
        });
        const page = await context.newPage();
        page.on('pageerror', e => errors.push(e.message));
        const ready = async url => {
            await page.goto(url);
            await page.waitForFunction(() => document.title.startsWith('Neu:'));
        };
        await ready(base);
        // Upgrade from the former single-field identity must not choose a phone.
        await page.evaluate(() => localStorage.setItem('telepraxis_workplace', 'AltBearbeiter'));
        await ready(base);
        assert.equal(await page.locator('#user-input-setup').inputValue(), 'AltBearbeiter');
        assert.equal(await page.locator('#workplace-input-setup').inputValue(), '');
        await page.evaluate(() => {
            localStorage.setItem('telepraxis_user', 'Alter Benutzer');
            localStorage.setItem('telepraxis_client_id', 'alter-platz');
        });
        await ready(base + '?arbeitsplatz=empfang-1');
        assert.equal(await page.locator('#user-input-setup').inputValue(), '');
        assert.equal(await page.locator('#workplace-input-setup').inputValue(), 'empfang-1');
        assert.equal(await page.locator('#workplace-input-setup').isVisible(), false);
        assert.equal(await page.locator('#user-input-setup').isVisible(), true);
        assert.match(await page.locator('#left-column').textContent(), /Benutzer/);
        await page.locator('#user-input-setup').fill('Julia');
        assert.equal(await page.locator('#user-input-menu').inputValue(), 'Julia');
        await page.locator('#save-workplace-btn-setup').click();
        assert.equal(await page.locator('#place-setup-row').isVisible(), false);
        assert.equal(await page.locator('#left-column article').count(), 1);
        assert.equal(await page.locator('#middle-body article').count(), 1);
        assert.match(await page.locator('#left-column').textContent(), /bei Julia/);
        const call = new URL(await page.locator('#left-column .card-header-box a.phone-link').getAttribute('href'));
        const record = new URL(await page.locator('#left-column .card-header-box a.btn-record').getAttribute('href'));
        assert.equal(call.pathname, '/waehlen');
        assert.equal(record.pathname, '/patient-oeffnen');
        for (const url of [call, record]) {
            assert.equal(url.searchParams.get('client_id'), 'empfang-1');
            assert.equal(url.searchParams.get('telefon'), '+493055501111');
            assert.ok(url.href.includes('%2B493055501111'));
        }
        assert.equal(call.searchParams.get('sofort'), '1');
        assert.equal(record.searchParams.has('sofort'), false);
        assert.equal(record.searchParams.get('auto_close'), '1');
        assert.equal(call.searchParams.has('auto_close'), false);
        assert.equal(await page.locator('#left-column .card-header-box .btn-record').textContent(), 'Akte');
        // Polling must preserve the open comment, draft, focus and selection.
        await page.locator('#left-column [data-toggle-comment]').click();
        const editor = page.locator('#left-column textarea[data-comment-input]');
        await editor.fill('Unfertiger Kommentar');
        await editor.evaluate(el => el.setSelectionRange(2, 7));
        await page.waitForResponse(r => r.url().includes('ajax=list'));
        await page.waitForFunction(() => document.activeElement?.matches('[data-comment-input]') && document.activeElement.value === 'Unfertiger Kommentar');
        assert.deepEqual(await editor.evaluate(el => [el.selectionStart, el.selectionEnd]), [2, 7]);
        // New and completed table UI continues working, with a compact record link.
        await page.locator('[data-view-target="middle"][data-view-mode="table"]').click();
        assert.equal(await page.locator('#middle-body .btn-record').count(), 1);
        const middleRowButtons = page.locator('#middle-body .table-actions-middle button');
        assert.deepEqual(await middleRowButtons.allTextContents(), ['Dringend', 'Bearbeiten']);
        assert.equal(await middleRowButtons.nth(1).getAttribute('data-status'), 'in_bearbeitung');
        assert.equal(await page.locator('#middle-body .table-actions-middle').evaluate(el => getComputedStyle(el).flexDirection), 'column');
        // Bookmarks use drafts, allowing one field to be deliberately cleared.
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#bookmark-link-btn').click();
        const fullBookmark = new URL(await page.evaluate(() => navigator.clipboard.readText()));
        assert.equal(fullBookmark.searchParams.get('benutzer'), 'Julia');
        assert.equal(fullBookmark.searchParams.get('arbeitsplatz'), 'empfang-1');
        await page.locator('#user-input-menu').fill('');
        await page.locator('#bookmark-link-btn').click();
        let copied = new URL(await page.evaluate(() => navigator.clipboard.readText()));
        assert.equal(copied.searchParams.get('arbeitsplatz'), 'empfang-1');
        assert.equal(copied.searchParams.has('benutzer'), false);
        await ready(copied.href);
        assert.equal(await page.locator('#user-input-setup').inputValue(), '');
        await ready(base + '?benutzer=Dr.%20M%C3%BCller');
        assert.equal(await page.locator('#user-input-setup').inputValue(), 'Dr. Müller');
        assert.equal(await page.locator('#workplace-input-setup').inputValue(), '');
        assert.equal(await page.locator('#user-input-setup').isVisible(), false);
        assert.equal(await page.locator('#workplace-input-setup').isVisible(), true);
        assert.equal(await page.locator('.phone-actions a').count(), 0);
        await page.locator('[data-t2med-unavailable]').first().click();
        assert.match(await page.locator('#message').textContent(), /Platz/);
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#bookmark-link-btn').click();
        copied = new URL(await page.evaluate(() => navigator.clipboard.readText()));
        assert.equal(copied.searchParams.get('benutzer'), 'Dr. Müller');
        assert.equal(copied.searchParams.has('arbeitsplatz'), false);
        await ready(base + '?benutzer=Julia&arbeitsplatz=empfang-1');
        // Editing a draft must not silently change the active phone or case owner.
        assert.equal(await page.locator('#place-setup-row').isVisible(), false);
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#workplace-input-menu').fill('empfang-2');
        assert.equal(new URL(await page.locator('#left-column .card-header-box a.phone-link').getAttribute('href')).searchParams.get('client_id'), 'empfang-1');
        await page.locator('#save-workplace-btn-menu').click();
        assert.equal(new URL(await page.locator('#left-column .card-header-box a.phone-link').getAttribute('href')).searchParams.get('client_id'), 'empfang-2');
        await ready(base);
        assert.equal(await page.locator('#user-input-setup').inputValue(), 'Julia');
        assert.equal(await page.locator('#workplace-input-setup').inputValue(), 'empfang-2');
        // Exercise shrinking and growing after layout changes, with either set of counters.
        const checkHeader = async width => {
            await page.setViewportSize({width, height: 1000});
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const layout = await page.evaluate(() => {
                const title = document.querySelector('.topbar-title').getBoundingClientRect();
                const menu = document.querySelector('#menu-toggle-btn').getBoundingClientRect();
                const summary = document.querySelector('#header-summary');
                const bounds = summary.getBoundingClientRect();
                const chips = [...summary.children].filter(chip => chip.getClientRects().length);
                return {titleY: title.y, titleRight: title.right, menuY: menu.y, menuRight: menu.right, menuLeft: menu.left,
                    visible: chips.length, total: summary.children.length,
                    meta: document.querySelector('.topbar-title-meta').getClientRects().length > 0,
                    fits: chips.every(chip => {
                        const r = chip.getBoundingClientRect();
                        return r.left >= bounds.left - 1 && r.right <= bounds.right + 1
                            && Math.abs((r.top + r.bottom - menu.top - menu.bottom) / 2) < 1;
                    }), overflow: document.documentElement.scrollWidth > innerWidth};
            });
            assert.ok(Math.abs(layout.titleY - layout.menuY) < 20, `header alignment ${width}: ${JSON.stringify(layout)}`);
            assert.ok(layout.menuRight <= width && layout.titleRight <= layout.menuLeft && layout.fits, `header fit ${width}: ${JSON.stringify(layout)}`);
            assert.equal(await page.locator('#place-setup-row').isVisible(), false);
            assert.equal(layout.overflow, false, `horizontal overflow ${width}`);
            if (width === 2400) {
                assert.equal(layout.visible, layout.total);
                assert.equal(layout.meta, true);
            }
            if (width <= 390) {
                assert.ok(layout.visible < layout.total);
                assert.equal(layout.meta, false);
            }
        };
        for (const width of [2400, 1920, 1600, 1400, 1000, 768, 600, 390, 320]) {
            await checkHeader(width);
            if (process.env.V36_SCREENSHOT_DIR && [1600, 390].includes(width)) {
                fs.mkdirSync(process.env.V36_SCREENSHOT_DIR, {recursive: true});
                await page.screenshot({path: path.join(process.env.V36_SCREENSHOT_DIR, `v36-${width}.png`), fullPage: false});
            }
        }
        // The table button transfers the entry to the current user's work list.
        await ready(base + '?benutzer=Julia&arbeitsplatz=empfang-2');
        await page.locator('[data-view-target="middle"][data-view-mode="table"]').click();
        await page.locator('#middle-body .table-actions-middle [data-action="set_status"][data-status="in_bearbeitung"]').click();
        await page.waitForFunction(() => document.querySelectorAll('#middle-body .table-actions-middle').length === 0);
        const transferredEntry = JSON.parse(fs.readFileSync(path.join(site, 'inbox', 'other.json'), 'utf8'));
        assert.equal(transferredEntry.app.status, 'in_bearbeitung');
        assert.equal(transferredEntry.app.status_updated_benutzer, 'Julia');
        // Disabled middleware restores tel links and removes record actions.
        fs.writeFileSync(path.join(site, 'telepraxis-app.php'), source.replace('const TELEPRAXIS_T2MED_ENABLED = true;', 'const TELEPRAXIS_T2MED_ENABLED = false;'));
        await ready(base);
        assert.equal(await page.locator('.btn-record').count(), 0);
        assert.match(await page.locator('#left-column article[data-entry-file="example.json"] .card-header-box a.phone-link').getAttribute('href'), /^tel:/);
        // Multiple recordings of one prescription must render independently.
        const callId = '20261107_143025_123456';
        const audioRecord = {
            received_at: '2026-11-07T14:30:25+01:00',
            typ: 'rezeptbestellung',
            payload: {
                typ: 'rezeptbestellung', telefon: '+493055501113', medikamente: 'Testmedikament A, Testmedikament B',
                _kienzlefon: {
                    vorgang_id: callId,
                    audio: [
                        {feld:'vorname',datei:'audio/vorname.wav',datei_vorhanden:true,status:'transcribed'},
                        {feld:'medikamente',datei:'audio/medikament-01.wav',index:1,datei_vorhanden:true,status:'transcribed'},
                        {feld:'medikamente',datei:'audio/medikament-02.wav',index:2,datei_vorhanden:true,status:'transcribed'}
                    ]
                }
            },
            app: {status:'in_bearbeitung',status_updated_benutzer:'Julia',status_updated_arbeitsplatz:'empfang-1',comments:[]}
        };
        fs.writeFileSync(path.join(site, 'inbox', callId + '.json'), JSON.stringify(audioRecord));
        await ready(base + '?benutzer=Julia&arbeitsplatz=empfang-1');
        const recordingCard = page.locator(`#left-column article[data-entry-file="${callId}.json"]`);
        await recordingCard.locator('[data-toggle-recordings]').click();
        assert.deepEqual(await recordingCard.locator('.recordings-label').allTextContents(), ['Vorname', 'Medikament 1', 'Medikament 2']);
        assert.equal(await recordingCard.locator('audio[controls]').count(), 3);
        assert.equal(await recordingCard.locator('audio').nth(2).getAttribute('src'), `recordings/${callId}/audio/medikament-02.wav`);
        // Polling every 5 s must preserve the actual media element and its playback position.
        await page.evaluate(() => { window.__recordingPlayerBeforeRefresh = document.querySelector('.recordings-player-slot audio'); });
        await page.waitForResponse(response => response.url().includes('ajax=list'), {timeout: 12000});
        assert.equal(await page.evaluate(() => document.querySelector('.recordings-player-slot audio') === window.__recordingPlayerBeforeRefresh), true);
        await recordingCard.locator('[data-toggle-recordings]').click();
        assert.equal(await recordingCard.locator('audio').count(), 0);

        const initialList = await (await page.request.get(base + '?ajax=list')).json();
        assert.ok((await page.request.post(base, {form: {
            action: 'set_status', status: 'abgeschlossen', file: 'other.json', benutzer: 'Julia', csrf: initialList.csrf,
        }})).ok());
        assert.ok((await page.request.post(base, {form: {
            action: 'soft_delete', file: 'other.json', benutzer: 'Julia', csrf: initialList.csrf,
        }})).ok());
        assert.ok((await page.request.post(base, {form: {
            action: 'admin_login', password: 'integration-admin', csrf: initialList.csrf,
        }})).ok());
        assert.equal(fs.existsSync(path.join(site, 'inbox', 'papierkorb', 'other.json')), true);
        await ready(base);
        assert.equal(await page.locator('#trash-panel').isVisible(), false);
        const isList = response => new URL(response.url()).searchParams.get('ajax') === 'list';
        const [opened] = await Promise.all([
            page.waitForResponse(response => isList(response) && new URL(response.url()).searchParams.get('trash') === '1'),
            page.locator('#trash-arrow-btn').click(),
        ]);
        assert.equal((await opened.json()).entries.some(item => item.file === 'other.json' && item.deleted), true);
        const archivedCard = page.locator('#trash-body [data-entry-file="other.json"]');
        await archivedCard.waitFor();
        const [closed] = await Promise.all([
            page.waitForResponse(response => isList(response) && !new URL(response.url()).searchParams.has('trash')),
            page.locator('#trash-arrow-btn').click(),
        ]);
        assert.equal((await closed.json()).entries.some(item => item.deleted), false);
        assert.equal(await page.locator('#trash-panel').isVisible(), false);
        assert.equal(await archivedCard.count(), 0);

        // One box with separate checkmarks, confirmed ownership and a WAV from its own inbox directory.
        const multi = JSON.parse(fs.readFileSync(path.join(root, 'docs/vorgang-v1/beispiele/mehrere-anliegen.json')));
        const multiId = multi.payload._kienzlefon.vorgang_id;
        multi.app = {status: 'in_bearbeitung', bearbeiter: 'Julia'};
        fs.writeFileSync(path.join(site, 'inbox', multiId + '.json'), JSON.stringify(multi));
        const audioDir = path.join(site, 'inbox', multiId, 'audio');
        fs.mkdirSync(audioDir, {recursive: true});
        fs.writeFileSync(path.join(audioDir, 'a-002-medikament-01.wav'), Buffer.from('RIFF synthetic recording'));
        fs.writeFileSync(path.join(site, 'telepraxis-app.php'), source);
        await ready(base + '?benutzer=Julia&arbeitsplatz=empfang-1');
        const multiCard = page.locator(`#left-column article[data-entry-file="${multiId}.json"]`);
        assert.equal(await multiCard.count(), 1);
        assert.deepEqual(await multiCard.locator('.anliegen-tags').allTextContents(), ['RezeptÜberweisung']);
        assert.equal(await multiCard.locator('[data-anliegen-id]').count(), 3);
        const finish = multiCard.getByRole('button', {name: 'Fertig', exact: true});
        assert.equal(await finish.isDisabled(), true);
        for (const id of ['a-001', 'a-002', 'a-003']) {
            const checkbox = multiCard.locator(`[data-anliegen-id="${id}"]`);
            const [saved] = await Promise.all([
                page.waitForResponse(r => r.request().postData()?.includes('\r\n\r\nset_anliegen\r\n')),
                checkbox.check(),
            ]);
            assert.equal(saved.status(), 200);
            await multiCard.locator('.anliegen-part').filter({has: page.locator(`[data-anliegen-id="${id}"]`)})
                .getByText('Julia', {exact: false}).waitFor();
        }
        assert.equal(await finish.isEnabled(), true);
        await multiCard.locator('[data-toggle-recordings]').click();
        const audioUrl = new URL(await multiCard.locator('audio').getAttribute('src'), base);
        const range = await page.request.get(audioUrl.toString(), {headers: {Range: 'bytes=0-3'}});
        assert.equal(range.status(), 206);
        assert.equal(await range.text(), 'RIFF');

        // The browser only talks to its own PHP proxy, including for a confirmed change.
        await page.setViewportSize({width: 1600, height: 1000});
        const proxy = new URL('telepraxis-telephony.php?action=status', base);
        const readStatus = await page.request.get(proxy.toString());
        const statusText = await readStatus.text();
        assert.equal(readStatus.status(), 200, statusText);
        assert.equal(JSON.parse(statusText).internal_channel, undefined);
        await page.locator('#menu-toggle-btn').click();
        const [boot] = await Promise.all([
            page.waitForResponse(response => response.url().includes('action=bootstrap')),
            page.locator('#telephony-toggle').check(),
        ]);
        assert.equal(boot.status(), 200, await boot.text());
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#telephony-panel summary').click();
        const normal = page.getByRole('checkbox', {name: 'Normal 201'});
        await normal.waitFor();
        assert.equal(await normal.isChecked(), true);
        assert.equal(await page.getByRole('checkbox', {name: 'ROT 201'}).isDisabled(), true);
        assert.equal(await page.locator('[data-summary-key="warte"] strong').textContent(), '2');
        assert.match(await page.locator('[data-summary-key="heute"] strong').textContent(), /^\d+$/);
        const forbidden = new URL('telepraxis-telephony.php?action=routing', base);
        assert.equal((await page.request.post(forbidden.toString(), {data: fixture('routing-command'), headers: {Origin: 'https://foreign.example'}})).status(), 403);
        await normal.click();
        await page.waitForFunction(() => document.querySelector('[data-extension="201"][data-route="normal"]')?.disabled);
        assert.equal(await normal.isChecked(), true);
        assert.equal(await normal.isDisabled(), true);
        await page.waitForResponse(response => response.url().includes('action=request'));
        assert.equal(await normal.isChecked(), true);
        confirm = true;
        await page.waitForFunction(() => {
            const box = document.querySelector('[data-extension="201"][data-route="normal"]');
            return box && !box.checked && !box.disabled;
        });
        assert.equal(commands.length, 1);
        assert.equal(commands[0].extension, '201');
        assert.equal(commands[0].route, 'normal');
        assert.equal(commands[0].member, false);
        assert.ok(lookups.every(url => url.endsWith(commands[0].request_id)));
        confirm = false;
        overviewName = 'overview-statistics-gap';
        await page.waitForFunction(() => document.querySelector('[data-summary-key="heute"] strong')?.textContent.startsWith('≈'));
        overviewName = 'overview-control-unavailable-statistics-current';
        await page.waitForFunction(() => document.querySelector('[data-summary-key="warte"] strong')?.textContent === '—');
        assert.match(await page.locator('[data-summary-key="heute"] strong').textContent(), /^\d+$/);
        assert.ok(await page.locator('.btn-record').count());
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#t2med-patient-toggle').uncheck();
        assert.equal(await page.locator('.btn-record').count(), 0);
        assert.equal(await page.locator('#telephony-toggle').isChecked(), true);
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#telephony-panel summary').click();
        for (const width of [2400, 1600, 1000, 600, 390, 320, 2400]) await checkHeader(width);
        assert.equal(await page.locator('.telephony-counter:visible').count(), 4);

        assert.deepEqual(errors, []);
        assert.deepEqual(external, [], 'No external requests may be made');
        console.log('PASS: legacy UI, recordings, lazy trash, multiple requests, WAV byte ranges and the same-origin telephony workflow.');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        if (middleware) await new Promise(resolve => middleware.close(resolve));
        fs.rmSync(site, {recursive: true, force: true});
        fs.rmSync(config, {recursive: true, force: true});
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
