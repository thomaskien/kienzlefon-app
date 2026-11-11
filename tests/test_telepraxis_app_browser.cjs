/* Real-browser checks against a temporary PHP site; no middleware requests. */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const net = require('node:net');

(async () => {
    const root = path.resolve(__dirname, '..');
    const site = fs.mkdtempSync(path.join(os.tmpdir(), 'kienzlefon-v36-'));
    let server, browser;
    try {
        fs.mkdirSync(path.join(site, 'inbox'));
        fs.mkdirSync(path.join(site, 'sessions'));
        for (const name of ['telepraxis-app.php', 'telepraxis-sms.php', 'telepraxis-sms-queue.php']) {
            fs.copyFileSync(path.join(root, name), path.join(site, name));
        }
        let source = fs.readFileSync(path.join(site, 'telepraxis-app.php'), 'utf8');
        source = source.replace('const TELEPRAXIS_T2MED_ENABLED = false;', 'const TELEPRAXIS_T2MED_ENABLED = true;')
            .replace("const TELEPRAXIS_T2MED_BASE_URL = '';", "const TELEPRAXIS_T2MED_BASE_URL = 'http://middleware.example:8765';");
        fs.writeFileSync(path.join(site, 'telepraxis-app.php'), source);
        fs.writeFileSync(path.join(site, 'sms-credentials.json'), JSON.stringify({default_provider: 'none'}));
        const entry = {
            received_at: '2026-10-12T09:00:00+02:00',
            payload: {typ: 'rueckruf_details', vorname: 'Erika', nachname: 'Beispiel', telefon: '+493055501111', id: '+493055501112', grund: 'Bitte um Rückruf.', zusammenfassung: 'Synthetischer Testvorgang.'},
            app: {status: 'in_bearbeitung', status_updated_benutzer: 'Julia', status_updated_arbeitsplatz: 'anderer-platz', comments: []},
        };
        fs.writeFileSync(path.join(site, 'inbox', 'example.json'), JSON.stringify(entry));
        fs.writeFileSync(path.join(site, 'inbox', 'other.json'), JSON.stringify({...entry, app: {...entry.app, status_updated_benutzer: 'Anna', status_updated_arbeitsplatz: 'empfang-1'}}));
        // Ask the OS for a loopback port, then start the temporary PHP server.
        const allocator = net.createServer();
        await new Promise((resolve, reject) => { allocator.once('error', reject); allocator.listen(0, '127.0.0.1', resolve); });
        const port = allocator.address().port;
        await new Promise(resolve => allocator.close(resolve));
        server = spawn('php', ['-d', 'opcache.enable=0', '-d', `session.save_path=${path.join(site, 'sessions')}`, '-S', `127.0.0.1:${port}`, '-t', site], {stdio: ['ignore', 'ignore', 'pipe']});
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
        assert.match(await page.locator('#left-column').textContent(), /Benutzer/);
        await page.locator('#user-input-setup').fill('Julia');
        assert.equal(await page.locator('#user-input-menu').inputValue(), 'Julia');
        await page.locator('#save-workplace-btn-setup').click();
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
        await page.locator('#workplace-input-setup').fill('empfang-2');
        assert.equal(new URL(await page.locator('#left-column .card-header-box a.phone-link').getAttribute('href')).searchParams.get('client_id'), 'empfang-1');
        await page.locator('#save-workplace-btn-setup').click();
        assert.equal(new URL(await page.locator('#left-column .card-header-box a.phone-link').getAttribute('href')).searchParams.get('client_id'), 'empfang-2');
        await ready(base);
        assert.equal(await page.locator('#user-input-setup').inputValue(), 'Julia');
        assert.equal(await page.locator('#workplace-input-setup').inputValue(), 'empfang-2');
        // Header and hamburger stay visible and aligned, including phone-sized widths.
        for (const width of [1920, 1600, 1400, 1000, 768, 600, 390, 320]) {
            await page.setViewportSize({width, height: 1000});
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            const layout = await page.evaluate(() => {
                const title = document.querySelector('.topbar-title').getBoundingClientRect();
                const menu = document.querySelector('#menu-toggle-btn').getBoundingClientRect();
                const user = document.querySelector('#user-input-setup').getBoundingClientRect();
                const place = document.querySelector('#workplace-input-setup').getBoundingClientRect();
                return {titleY: title.y, menuY: menu.y, menuRight: menu.right, userWidth: user.width, placeWidth: place.width, overflow: document.documentElement.scrollWidth > innerWidth};
            });
            assert.ok(Math.abs(layout.titleY - layout.menuY) < 20, `header alignment ${width}: ${JSON.stringify(layout)}`);
            assert.ok(layout.menuRight <= width && layout.userWidth > 40 && layout.placeWidth > 40, `header inputs ${width}`);
            assert.equal(layout.overflow, false, `horizontal overflow ${width}`);
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
        assert.match(await page.locator('#left-column a.phone-link').getAttribute('href'), /^tel:/);
        assert.deepEqual(errors, []);
        assert.deepEqual(external, [], 'No external requests may be made');
        console.log('PASS: bookmarks, storage, ownership, URLs, Akte, tables, polling focus and eight viewport sizes; no external requests.');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fs.rmSync(site, {recursive: true, force: true});
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
