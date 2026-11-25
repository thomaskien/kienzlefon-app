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
            app: {rufnummernaufloesung: [{nummer:'+493055501111', status:'mehrere_treffer', treffer:[
                {patientennummer:55, name:'Erika Marie von der Weide', vorname:'Erika Marie', nachname:'von der Weide', geburtsdatum:'1980-03-02'},
                {patientennummer:56, name:'Leo Muster', vorname:'Leo', nachname:'Muster', geburtsdatum:null}]}],
                status: 'in_bearbeitung', status_updated_benutzer: 'Julia', status_updated_arbeitsplatz: 'anderer-platz', comments: []},
        };
        fs.writeFileSync(path.join(site, 'inbox', 'example.json'), JSON.stringify(entry));
        fs.writeFileSync(path.join(site, 'inbox', 'other.json'), JSON.stringify({...entry, app: {...entry.app, status_updated_benutzer: 'Anna', status_updated_arbeitsplatz: 'empfang-1'}}));
        const fixtures = JSON.parse(fs.readFileSync(path.join(root, 'docs/telefonie-v1/fixtures.json'))).fixtures;
        const fixture = name => structuredClone(fixtures.find(item => item.name === name).body);
        const token = randomBytes(32).toString('hex');
        const tokenFile = path.join(config, 'token');
        fs.writeFileSync(tokenFile, token, {mode: 0o600});
        const deskToken=randomBytes(32).toString('hex'), lookupToken=randomBytes(32).toString('hex');
        const workOrders=[], lookupNumbers=[];
        let activeJob=false, aiEnabled=false, lookupDown=false;
        let overviewName = 'overview-current';
        let commands = [], confirm = false, lookups = [], answered = false;
        let paired = false, normal203 = true, revision203 = 17;
        middleware = http.createServer(async (req, res) => {
            res.setHeader('Content-Type', 'application/json');
            if (req.url?.startsWith('/api/v1/practice/')) {
                const action=req.url.split('/').at(-1);
                if (req.headers.authorization !== 'Bearer '+(action==='lookup'?lookupToken:deskToken)) {res.writeHead(401).end('{}');return;}
                const chunks=[]; for await (const chunk of req) chunks.push(chunk);
                const input=JSON.parse(Buffer.concat(chunks));
                if (action==='lookup') {
                    if (input.abfrage === 'namensfelder') { res.end(JSON.stringify({namensfelder:true})); return; }
                    lookupNumbers.push(input.number);
                    if (lookupDown && input.number==='+493055502222') { res.writeHead(503).end('{}'); return; }
                    res.end(JSON.stringify({status:'ambiguous',namensfelder:true,treffer:[
                        {patientennummer:55,name:'Erika Muster',vorname:'Erika',nachname:'Muster',geburtsdatum:'1980-03-02'},
                        {patientennummer:57,name:'Leo Muster',vorname:'Leo',nachname:'Muster',geburtsdatum:null}]}));
                } else if(action==='desk') {
                    res.end(JSON.stringify({client_id:input.client_id,extension:'201',calls:[
                        {id:'demo-call',state:'connected',name:'Test Anruf',number:'03055501',can_transfer:!activeJob,
                            treffer:[{patientennummer:42,name:'Test Anruf',vorname:'Test',nachname:'Anruf'}]},
                        {id:'incoming',state:answered?'connected':'ringing',name:'',number:'03055502',can_transfer:false,
                            treffer:[{patientennummer:55,name:'Erika Muster',vorname:'Erika',nachname:'Muster',geburtsdatum:'1980-03-02'},
                                {patientennummer:57,name:'Leo Muster',vorname:'Leo',nachname:'Muster'}, {patientennummer:58,name:'Mia Muster',vorname:'Mia',nachname:'Muster'}]},
                        {id:'unknown-call',state:answered?'connected':'ringing',name:'',number:'01701234567',treffer:[],can_transfer:false}],
                        transfers:activeJob?[{request_id:'test-job',phase:'consulting',target:'202',mode:'attended',can_cancel:true,can_complete:true}]:[]}));
                } else {
                    workOrders.push(input);
                    activeJob=action==='transfer' && input.mode==='attended';
                    res.end(JSON.stringify({message:'Testauftrag angenommen.'}));
                }
                return;
            }
            if (req.headers.authorization !== `Bearer ${token}`) { res.writeHead(401).end('{}'); return; }
            if (paired && req.url === '/api/v1/extension-routing/workplaces') {
                res.end(JSON.stringify({schema_version:1, pbx_id:'praxis-a', mode:'all', client_ids:[], remote_enabled:true}));
                return;
            }
            if (req.url === '/api/v1/telephony/overview') {
                const overview = fixture(overviewName);
                overview.ai_enabled=aiEnabled;
                if (aiEnabled) overview.live_counters.ai=0;
                overview.generated_at = new Date().toISOString();
                overview.call_statistics.today.date = new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/Berlin', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
                if (confirm) {
                    overview.routing_control.revision = overview.routing_control.revision.replace(/:17$/, ':18');
                    overview.extensions[0].routing.normal = false;
                }
                if (paired) {
                    overview.routing_control.revision = overview.routing_control.revision.replace(/:\d+$/, ':' + revision203);
                    const ext = structuredClone(overview.extensions[0]);
                    ext.id = '203';
                    ext.routing.normal = normal203;
                    ext.routing.permissions.normal = {can_enable:true, can_disable:true, enable_reason:null, disable_reason:null};
                    overview.extensions.push(ext);
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
                if (paired) {
                    assert.equal(command.client_id, req.headers['x-kienzlefon-workplace']);
                    assert.equal(req.headers['x-kienzlefon-pbx-id'], 'praxis-a');
                    assert.equal(command.extension, '203');
                    normal203 = command.member;
                    revision203++;
                    const job = {...fixture('request-applied').request, ...command,
                        result_revision:command.expected_revision.replace(/:\d+$/, ':' + revision203)};
                    res.end(JSON.stringify({request:job, error:null}));
                    return;
                }
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
        await page.waitForFunction(() => document.getElementById('telephony-toggle').checked);
        await page.evaluate(() => localStorage.setItem('kienzlefon_telephony_enabled', '0'));
        await ready(base);
        assert.equal(await page.locator('#telephony-toggle').isChecked(), false);
        await page.evaluate(() => localStorage.removeItem('kienzlefon_telephony_enabled'));
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
        for (const width of [390, 1600]) {
            await page.setViewportSize({width, height:1000});
            for (const column of ['#middle-body', '#left-column']) {
                const layout = await page.locator(column + ' article').evaluate(card => {
                    const tag = card.querySelector('.category-tag').getBoundingClientRect();
                    const box = card.querySelector('.card-header-box').getBoundingClientRect();
                    const select = card.querySelector('.selection-row')?.getBoundingClientRect();
                    return {above:tag.bottom <= box.top, right:Math.abs(tag.right - box.right),
                        beside:select && select.right <= tag.left && select.top < tag.bottom && select.bottom > tag.top};
                });
                assert.ok(layout.above && layout.right <= 1, `Kategorie oben rechts in ${column} bei ${width}px`);
                if (column === '#middle-body') assert.ok(layout.beside, 'Auswählen und Kategorie teilen eine Zeile');
                const contents = page.locator(column + ' article .header-matches');
                const name = contents.locator('.patient-label').first();
                assert.equal(await name.textContent(), 'von der Weide, Erika Marie');
                assert.equal(await name.evaluate(el => {
                    const range = document.createRange();
                    range.selectNodeContents(el);
                    return range.getClientRects().length;
                }), 1);
                assert.ok(await contents.locator('.phone-digits').evaluate(el => el.scrollWidth <= el.clientWidth),
                    'Die vollständige Nummer passt gegebenenfalls in die nächste Zeile');
            }
        }
        assert.match(await page.locator('#left-column').textContent(), /bei Julia/);
        const call = new URL(await page.locator('#left-column .card-header-box a.phone-link').getAttribute('href'));
        assert.equal(call.pathname, '/waehlen');
        assert.equal(call.searchParams.get('client_id'), 'empfang-1');
        assert.equal(call.searchParams.get('telefon'), '+493055501111');
        assert.equal(call.searchParams.get('sofort'), '1');
        assert.equal(call.searchParams.get('auto_close'), '1');
        assert.equal(await page.locator('.btn-record').count(), 0);
        assert.equal(await page.locator('#left-column .patient-hit').count(), 2);
        const phoneButton = page.locator('#left-column .header-matches .phone-link');
        const phoneTip = page.locator('#phone-tooltip');
        await phoneButton.hover();
        assert.equal(await phoneTip.isVisible(),false);
        const veryNarrow = await page.addStyleTag({content:'#left-column .header-matches {width:72px}'});
        await phoneButton.hover();
        await phoneTip.waitFor();
        assert.equal(await phoneTip.textContent(), await phoneButton.textContent());
        const tipBox = await phoneTip.boundingBox(), phoneBox = await phoneButton.boundingBox();
        assert.ok(tipBox.y + tipBox.height <= phoneBox.y);
        await veryNarrow.evaluate(el => el.remove());
        await phoneTip.waitFor({state:'hidden'});
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
        assert.equal(await page.locator('#middle-body .patient-hit').count(), 2);
        const middleRowButtons = page.locator('#middle-body .table-actions-middle button');
        assert.deepEqual(await middleRowButtons.allTextContents(), ['Dringend', 'Bearbeiten']);
        assert.equal(await middleRowButtons.nth(1).getAttribute('data-status'), 'in_bearbeitung');
        assert.equal(await page.locator('#middle-body .table-actions-middle').evaluate(el => getComputedStyle(el).flexDirection), 'column');
        // Bookmarks use drafts, allowing one field to be deliberately cleared.
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#bookmark-link-btn').click();
        assert.equal(await page.locator('label[for="workplace-input-menu"]').textContent(), 'Telefon');
        assert.equal(await page.locator('label[for="workplace-input-setup"]').textContent(), 'Telefon');
        assert.match(await page.locator('.menu-heading').first().textContent(), /Benutzer und Telefon/);
        assert.match(await page.locator('[data-summary-key="platz"]').textContent(), /Telefon:\s*empfang-1/);
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
        assert.match(await page.locator('#message').textContent(), /Feld „Telefon“/);
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
        await page.waitForFunction(() => document.getElementById('telephony-toggle').checked);
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
        const legacyConfig = fs.readFileSync(configFile, 'utf8');
        paired = true;
        fs.writeFileSync(configFile, `<?php return ['enabled'=>true,'pbx_id'=>'praxis-a','clients'=>[],
            'middleware_url'=>'http://127.0.0.1:${middleware.address().port}','token_file'=>__DIR__.'/token'];`);
        await page.waitForResponse(async res => res.url().includes('action=routing-access') && Boolean((await res.json()).client_id));
        const normalPaired = page.getByRole('checkbox', {name:'Normal 203'});
        for (const expected of [false, true]) {
            await normalPaired.click();
            await page.waitForFunction(value => {
                const box = document.querySelector('input[data-extension="203"][data-route="normal"]');
                return box && !box.disabled && box.checked === value;
            }, expected);
            assert.equal(commands.at(-1).member, expected);
            assert.equal(await page.getByRole('checkbox', {name:'ROT 203'}).isDisabled(), true);
        }
        paired = false;
        fs.writeFileSync(configFile, legacyConfig);
        overviewName = 'overview-statistics-gap';
        await page.waitForResponse(r => r.url().includes('action=status'));
        assert.match(await page.locator('[data-summary-key="heute"] strong').textContent(), /^\d+$/);
        overviewName = 'overview-control-unavailable-statistics-current';
        await page.waitForFunction(() => !document.querySelector('[data-summary-key="warte"]'));
        assert.match(await page.locator('[data-summary-key="heute"] strong').textContent(), /^\d+$/);
        assert.ok(await page.locator('.patient-hit').count());
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#t2med-patient-toggle').uncheck();
        assert.equal(await page.locator('.patient-hit').count(), 0);
        assert.equal(await page.locator('#telephony-toggle').isChecked(), true);
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#telephony-panel summary').click();
        for (const width of [2400, 1600, 1000, 600, 390, 320, 2400]) await checkHeader(width);
        assert.equal(await page.locator('.telephony-counter:visible').count(), 1);

        // Install the optional scoped credentials into this isolated fixture.
        fs.writeFileSync(tokenFile+'.workplace', deskToken, {mode:0o600});
        fs.writeFileSync(tokenFile+'.lookup', lookupToken, {mode:0o600});
        const inputRecord = {payload:{name:'Angesagter Name', telefon:'030 55502222',anrufer_id:'+493055502222'}, app:{status:'neu'}};
        const inputFile = path.join(site,'inbox','lookup-demo.json');
        fs.writeFileSync(inputFile,JSON.stringify(inputRecord));
        const closedFile = path.join(site,'inbox','lookup-closed.json');
        fs.writeFileSync(closedFile, JSON.stringify({...inputRecord, app:{status:'abgeschlossen'}}));
        const legacyFile = path.join(site,'inbox','lookup-legacy.json');
        const oldHit = {patientennummer:57,name:'Leo Muster',geburtsdatum:null};
        fs.writeFileSync(legacyFile,JSON.stringify({payload:{telefon:'03055503333'},app:{status:'abgeschlossen',
            rufnummernaufloesung:[{nummer:'+493055503333',status:'ein_treffer',treffer:[oldHit],stand:'2026-11-20'}]}}));
        const runLookup = () => new Promise((resolve,reject) => require('node:child_process').execFile('php',
            [path.join(site,'telepraxis-app.php'),'--resolve-inbox'], {env:{...process.env,KIENZLEFON_TELEPHONY_CONFIG:configFile}},
            error => error ? reject(error) : resolve()));
        lookupDown=true;
        await assert.rejects(runLookup);
        const pending = JSON.parse(fs.readFileSync(inputFile));
        assert.equal(pending.app.rufnummernaufloesung[0].status,'ausstehend');
        pending.app.comments=[{text:'Zwischenzeitlich ergänzt'}];
        fs.writeFileSync(inputFile,JSON.stringify(pending));
        lookupDown=false;
        await runLookup();
        await runLookup();
        assert.equal(lookupNumbers.filter(n => n === '+493055502222').length, 2);
        const saved = JSON.parse(fs.readFileSync(inputFile));
        assert.equal(saved.payload.name,'Angesagter Name');
        assert.equal(saved.app.comments[0].text,'Zwischenzeitlich ergänzt');
        assert.deepEqual(saved.app.rufnummernaufloesung[0].nummernfelder,['telefon','anrufer_id']);
        assert.equal(saved.app.rufnummernaufloesung[0].treffer.length,2);
        const [legacy] = JSON.parse(fs.readFileSync(legacyFile)).app.rufnummernaufloesung;
        assert.equal(lookupNumbers.filter(n=>n==='+493055503333').length,1);
        assert.deepEqual(legacy.treffer,[{...oldHit,vorname:'Leo',nachname:'Muster'}]);
        assert.equal(legacy.stand,'2026-11-20');
        assert.equal(JSON.parse(fs.readFileSync(closedFile)).app.rufnummernaufloesung,undefined);
        overviewName='overview-current';
        await page.evaluate(() => {localStorage.setItem('kienzlefon_t2med_patient_enabled','1'); localStorage.setItem('kienzlefon_telephony_enabled','1');});
        await ready(base+'?benutzer=Julia&arbeitsplatz=empfang-1');
        assert.equal(await page.locator('[data-summary-key="ai"]').count(),0);
        aiEnabled=true;
        await page.waitForFunction(() => document.querySelector('[data-summary-key="ai"] strong')?.textContent === '0');
        aiEnabled=false;
        await page.waitForFunction(() => !document.querySelector('[data-summary-key="ai"]'));
        const announce = page.locator('[data-entry-file="lookup-demo.json"]');
        await announce.locator('[data-patient="57"]').click();
        await page.waitForFunction(() => document.querySelector('#message').textContent.includes('Testauftrag'));
        assert.equal(workOrders.at(-1).patient_number,57);
        assert.equal(workOrders.at(-1).client_id,'empfang-1');
        await page.keyboard.press('t');
        await page.waitForSelector('#telephony-panel[open] kbd');
        await page.keyboard.press('t');
        assert.equal(await page.locator('#telephony-panel').evaluate(el => el.open), false);
        const orderCount = workOrders.length;
        await page.keyboard.press('2');
        await page.keyboard.press('t');
        await page.waitForSelector('#telephony-panel[open] kbd');
        const dialResponse = page.waitForResponse(r => r.url().includes('action=dial'));
        await page.keyboard.press('2');
        await dialResponse;
        assert.equal(workOrders.length, orderCount + 1);
        assert.equal(workOrders.at(-1).number,'202');
        assert.equal(context.pages().length,1);
        await page.keyboard.press('t');
        assert.equal(await page.locator('#telephony-panel').evaluate(el => el.open), false);
        await page.locator('#menu-toggle-btn').click();
        await page.locator('#workplace-input-menu').focus();
        await page.keyboard.type('t');
        assert.equal(await page.locator('#telephony-panel').evaluate(e=>e.open),false);
        await page.locator('#workplace-input-menu').fill('empfang-1');
        await page.locator('#menu-toggle-btn').click();
        await page.setViewportSize({width:390, height:900});
        await page.locator('#telephony-panel summary').click();
        await page.locator('#menu-toggle-btn').click();
        const overlay = await page.locator('#header-menu').evaluate(menu => {
            const bounds = menu.getBoundingClientRect();
            for (const card of document.querySelectorAll('.telephony-extension')) {
                const rect = card.getBoundingClientRect();
                const left = Math.max(bounds.left, rect.left), right = Math.min(bounds.right, rect.right);
                const top = Math.max(bounds.top, rect.top), bottom = Math.min(bounds.bottom, rect.bottom);
                if (right - left > 12 && bottom - top > 12) {
                    return menu.contains(document.elementFromPoint((left + right) / 2, (top + bottom) / 2));
                }
            }
            return false;
        });
        assert.ok(overlay, 'Das Hamburger-Menü liegt vor den geöffneten Nebenstellen');
        await page.locator('#user-input-menu').click();
        assert.equal(await page.locator('#user-input-menu').evaluate(input => input === document.activeElement), true);
        await page.locator('#menu-toggle-btn').click();
        await page.setViewportSize({width:1600, height:1000});
        const incoming = page.locator('[data-call-id="incoming"]');
        const patientNames = incoming.locator('button[data-patient] > span:first-child');
        await incoming.locator('[data-patient="58"]').waitFor();
        assert.deepEqual(await patientNames.allTextContents(), ['Muster, Erika','Muster, Leo','Muster, Mia']);
        assert.equal(await incoming.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(255, 230, 155)');
        assert.match(await incoming.innerText(), /Muster, Erika\s+oder\s+Muster, Leo\s+oder\s+Muster, Mia\s+\(03055502\)/);
        const unknown = page.locator('[data-call-id="unknown-call"]');
        assert.equal((await unknown.innerText()).trim().replace(/\s+/g,' '), 'Klingelt 01701234567');
        assert.ok(await unknown.evaluate(el => {
            const number = el.querySelector('.call-number').getBoundingClientRect();
            return number.top < el.querySelector('b').getBoundingClientRect().bottom;
        }));
        assert.equal(await unknown.locator('button').count(),0);
        assert.equal(await incoming.locator('[data-menu]').count(), 0);
        const beforePatient = workOrders.length;
        await Promise.all([page.waitForResponse(r => r.url().includes('action=patient')),
            incoming.getByRole('button',{name:'Muster, Leo aus T2med'}).click()]);
        assert.equal(workOrders.at(-1).patient_number,57);
        assert.equal(workOrders.at(-1).number,'03055502');
        answered = true;
        await page.locator('[data-call-id="incoming"].connected').waitFor();
        assert.equal(await incoming.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(206, 241, 213)');
        assert.equal(await page.locator('[data-call-id="incoming"]').count(),1);
        assert.equal((await unknown.innerText()).trim().replace(/\s+/g,' '), 'Im Gespräch 01701234567');
        assert.match(await incoming.innerText(), /\(03055502\)/);
        assert.deepEqual(await patientNames.allTextContents(), ['Muster, Erika','Muster, Leo','Muster, Mia']);
        const erika = incoming.getByRole('button',{name:'Muster, Erika, 2.3.1980 aus T2med',exact:true});
        await erika.focus();
        await Promise.all([page.waitForResponse(r => r.url().includes('action=patient')), erika.press('Enter')]);
        assert.equal(workOrders.length, beforePatient + 2);
        assert.equal(workOrders.at(-1).patient_number,55);
        assert.equal(workOrders.at(-1).client_id,'empfang-1');
        assert.equal(workOrders.at(-1).number,'03055502');
        assert.equal(context.pages().length,1);
        await page.getByRole('button',{name:'Vermitteln',exact:true}).click();
        const target = page.locator('select[data-target]');
        assert.equal(await page.getByRole('button',{name:'Direkt durchstellen'}).isDisabled(),true);
        await target.selectOption('202');
        await page.getByRole('button',{name:'Rückfrage starten'}).click();
        await page.getByRole('button',{name:'Zurück zum Anrufer'}).click();
        await page.waitForResponse(r=>r.url().includes('action=cancel'));
        assert.equal(workOrders.at(-1).transfer_id,'test-job');
        await page.getByRole('button',{name:'Vermitteln',exact:true}).click();
        await target.selectOption('202');
        await page.getByRole('button',{name:'Direkt durchstellen'}).click();
        await page.waitForResponse(r=>r.url().includes('action=transfer'));
        assert.equal(workOrders.at(-1).mode,'blind');
        await page.locator('.middle-column .area-open').focus();
        await page.keyboard.press('Tab');
        assert.equal(await page.locator('.right-column .area-open').evaluate(e=>e===document.activeElement),true);
        await page.keyboard.press('Shift+Tab');
        await page.keyboard.press('Enter');
        const selectedBox = page.locator('#middle-body input[type=checkbox]').first();
        assert.equal(await selectedBox.evaluate(e=>e===document.activeElement),true);
        await page.keyboard.press('Space');
        assert.equal(await selectedBox.isChecked(),true);

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
