/**
 * Browser smoke test of the checklist page, with Playwright.
 *
 *   CKO_URL='https://example.test/checklists/' CKO_WP='wp --path=/var/www/site' node tools/smoke-test.js
 *
 * CKO_URL is the page that shows checklists ([cko_app] or the chosen
 * checklist page); CKO_WP the wp-cli command for the same site (default
 * "wp"). The test creates its own checklists with tools/smoke-fixture.php,
 * deletes them afterwards and exits 1 if anything failed. It reads no
 * translated text, so it runs in any site language.
 *
 * Covers: every building block draws and ticks, progress, the board and
 * moving a card, reload keeps the ticks, no "updated from another device"
 * after own ticks, paging and ticking a round ahead, a note typed before
 * paging stays in its round, ticking offline, an add-on module, and no
 * sideways scrolling on a phone.
 */

/* eslint-disable no-console */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const path = require('path');

const PAGE = process.env.CKO_URL;
const WP = process.env.CKO_WP || 'wp';
const FIXTURE = path.join(__dirname, 'smoke-fixture.php');

if (!PAGE) {
    console.error('Set CKO_URL to the page that shows checklists.');
    process.exit(2);
}

let passed = 0;
let failed = 0;

function check(condition, message) {
    if (condition) {
        passed++;
    } else {
        failed++;
        console.log('FAIL: ' + message);
    }
}

function wp(args) {
    return execSync(WP + ' eval-file ' + JSON.stringify(FIXTURE) + (args ? ' ' + args : ''), { encoding: 'utf8' });
}

function open(page, id, hash) {
    const url = new URL(PAGE);
    url.searchParams.set('cko', id);
    return page.goto(url.toString() + (hash || '')).then(() => page.waitForSelector('.cko-card'));
}

const pause = (page, ms) => page.waitForTimeout(ms);

/** done/total of the whole list, from its progress bar. */
async function progress(page) {
    const text = await page.locator('.cko-head .cko-progress').first().innerText();
    const m = text.match(/(\d+)\D+(\d+)/);
    return m ? [Number(m[1]), Number(m[2])] : null;
}

/** The text of an element, or '' when there is none — a missing element is a failed check, not a crash. */
async function textOf(locator) {
    return (await locator.count()) ? (await locator.first().innerText()) : '';
}

async function boardStage(page, item) {
    const card = page.locator(`[data-card="${item}"]`);
    return (await card.count()) ? card.evaluate((node) => node.closest('[data-board-cell]').dataset.stage) : '';
}

(async () => {
    const ids = JSON.parse(wp('').trim().split('\n').pop());
    const browser = await chromium.launch();
    const errors = [];

    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        // An add-on module, registered before the frontend runs: a summary of its own for notes.
        await context.addInitScript(() => {
            window.checkoffBlockTypes = window.checkoffBlockTypes || [];
            window.checkoffBlockTypes.push(function (kit) {
                return { key: 'note', summary: function (value) { return kit.hasValue(value) ? 'ADDON:' + value : ''; } };
            });
        });
        const page = await context.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        page.on('console', (m) => m.type() === 'error' && !/ERR_INTERNET_DISCONNECTED/.test(m.text()) && errors.push(m.text()));

        /* --- every building block ------------------------------------------------- */

        await open(page, ids.all);
        check(await page.locator('.cko-item').count() === ids.blocks, 'every building block draws its entry');
        check(await page.locator('.cko-block__type svg').count() === ids.blocks, 'every building block has its icon');
        check((await progress(page))[0] === 0, 'nothing is done at first');

        await page.click('[data-focus="check-ismk00001"]');
        await page.click('[data-focus="skip-ismk00003"]');
        await page.click('[data-focus="plus-ismk00009"]');
        await page.click('[data-focus="plus-ismk00009"]');
        await page.locator('[data-item="ismk00004"] .cko-day').first().click();
        await page.locator('[data-item="ismk00005"] .cko-swatch').first().click();
        await page.locator('[data-item="ismk00006"] .cko-number').nth(2).click();
        await page.fill('[data-focus="measure-ismk00007"]', '3');
        await page.keyboard.press('Enter');
        await page.locator('[data-item="ismk00008"] .cko-choice__option').first().click();
        await page.fill('[data-focus="note-ismk00010"]', 'hello');
        await page.locator('body').click({ position: { x: 2, y: 2 } });
        await pause(page, 1500);

        const ticked = await progress(page);
        check(ticked && ticked[0] === 7, 'progress counts the done entries (got ' + JSON.stringify(ticked) + ')');
        check(await page.locator('.cko-item.is-done').count() === 6, 'done entries are marked (weekdays: one of three is not done)');
        check(await page.locator('.cko-item.is-settled').count() === 1, '"not needed" is marked as settled');

        await pause(page, 5000); // a few polls
        const status = await page.locator('.cko-savestate').innerText();
        const updated = await page.evaluate(() => window.checkoff.i18n.updated);
        check(status.indexOf(updated) === -1, 'no "updated from another device" after own ticks');

        await page.reload();
        await page.waitForSelector('.cko-card');
        check(JSON.stringify(await progress(page)) === JSON.stringify(ticked), 'a reload shows the same ticks');

        /* --- board ------------------------------------------------------------------ */

        await page.locator('.cko-viewswitch button').nth(1).click();
        check(await page.locator('.cko-kcard').count() === ids.blocks, 'the board shows every entry as a card');
        check(await boardStage(page, 'ismk00001') === 'done', 'a ticked entry stands in done');
        check(await boardStage(page, 'ismk00002') === 'open', 'an untouched entry stands in open');
        check(await boardStage(page, 'ismk00010') === 'done', 'a filled note stands in done');
        check((await textOf(page.locator('[data-card="ismk00010"] .cko-kcard__value'))) === 'ADDON:hello', 'the add-on module draws the card summary');

        await page.click('[data-focus="move-ismk00002"]');
        await page.locator('.cko-menu__item').nth(2).click();
        await pause(page, 800);
        check(await boardStage(page, 'ismk00002') === 'done', 'moving a card to done ticks it');
        await page.click('[data-focus="move-ismk00008"]');
        await page.locator('.cko-menu__item').nth(1).click();
        await pause(page, 800);
        check(await boardStage(page, 'ismk00008') === 'doing', 'moving a done card to in progress');
        await page.locator('.cko-viewswitch button').nth(0).click();

        /* --- rounds ahead ----------------------------------------------------------- */

        await open(page, ids.weekly);
        check(await page.locator('[data-focus="round-next"]').isEnabled(), 'a repeating list pages forward');
        await page.fill('[data-focus="note-ismk00012"]', 'now');
        await page.click('[data-focus="round-next"]'); // before the note's typing pause is over
        await pause(page, 1000);
        check(await page.locator('.cko-pastbar--future').count() === 1, 'a round ahead says so');
        check((await page.inputValue('[data-focus="note-ismk00012"]')) === '', 'a note typed before paging stays in its round');
        await page.click('[data-focus="check-ismk00011"]');
        await pause(page, 800);
        await page.click('[data-focus="round-live"]');
        await pause(page, 800);
        check(!(await page.isChecked('[data-focus="check-ismk00011"]')), 'the round in force is untouched by a tick ahead');
        await page.reload();
        await page.waitForSelector('.cko-card');
        check((await page.inputValue('[data-focus="note-ismk00012"]')) === 'now', 'the note was saved in the round it was typed in');
        await page.click('[data-focus="round-next"]');
        await pause(page, 1000);
        check(await page.isChecked('[data-focus="check-ismk00011"]'), 'the tick ahead is kept');
        await page.click('[data-focus="round-live"]');
        await pause(page, 500);

        /* --- offline ---------------------------------------------------------------- */

        const queueSize = () => page.evaluate(() => {
            const key = Object.keys(localStorage).find((k) => k.indexOf('cko_queue_') === 0);
            return key ? Object.keys(JSON.parse(localStorage.getItem(key))).length : 0;
        });
        await context.setOffline(true);
        await page.click('[data-focus="check-ismk00011"]');
        await pause(page, 800);
        check(await queueSize() === 1, 'a tick without a connection waits in the queue');
        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await pause(page, 2000);
        check(await queueSize() === 0, 'the queue is sent once the connection is back');
        await page.reload();
        await page.waitForSelector('.cko-card');
        check(await page.isChecked('[data-focus="check-ismk00011"]'), 'the tick sent late is stored');

        /* --- a module registered after the page is running --------------------------- */

        await page.evaluate(() => window.checkoffBlockTypes && window.checkoffBlockTypes.push({ key: 'simple', summary: () => 'LATE' }));
        await page.locator('.cko-viewswitch button').nth(1).click();
        check((await textOf(page.locator('[data-card="ismk00011"] .cko-kcard__value'))) === 'LATE', 'a module registered later is used from the next drawing on');

        /* --- phone ------------------------------------------------------------------- */

        const phone = await browser.newPage({ viewport: { width: 390, height: 800 } });
        phone.on('pageerror', (e) => errors.push(e.message));
        await open(phone, ids.all);
        check(await phone.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 0, 'no sideways scrolling on a phone (list)');
        await phone.locator('.cko-viewswitch button').nth(1).click();
        check(await phone.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 0, 'no sideways scrolling on a phone (board)');

        check(errors.length === 0, 'no script errors (' + errors.join(' | ') + ')');
    } finally {
        await browser.close();
        wp('cleanup');
    }

    console.log(passed + ' passed, ' + failed + ' failed');
    process.exit(failed ? 1 : 0);
})().catch((e) => {
    console.error(e);
    try {
        wp('cleanup');
    } catch (ignore) {
        // nothing more to do
    }
    process.exit(1);
});
