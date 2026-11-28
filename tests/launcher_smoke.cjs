// Real-browser checks for the replacement Help launcher; all network traffic stays local.
const assert = require('node:assert/strict');
const path = require('node:path');
const [modules, base, output] = process.argv.slice(2);
const { chromium } = require(path.join(modules, 'playwright-core'));

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  let count = 0;
  try {
    console.log('Browser:', browser.version());
    for (const role of ['owner', 'editor']) {
      for (const width of [360, 768, 1280]) {
        const context = await browser.newContext({ viewport: { width, height: 900 } });
        try {
          await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === base) return route.continue();
            return route.abort();
          });
          const page = await context.newPage();
          const errors = [];
          await page.goto(base + '/auth/login.php');
          await page.fill('#email', `ui-${role}@example.test`);
          await page.fill('#password', 'Local-Fixture-9');
          await Promise.all([page.waitForURL('**/customer/dashboard.php'), page.click('form button[type=submit]')]);
          // The Google SDK on sign-in is deliberately blocked. Check runtime/network
          // failures on the launcher pages after the password sign-in has succeeded.
          page.on('pageerror', error => errors.push(error.message));
          page.on('console', message => {
            const url = message.location().url;
            if (message.type() === 'error' && (!url || new URL(url).origin === base)) errors.push(message.text());
          });
          page.on('requestfailed', request => {
            if (new URL(request.url()).origin === base && request.failure()?.errorText !== 'net::ERR_ABORTED') {
              errors.push('Local request failed: ' + request.url());
            }
          });
          page.on('response', response => {
            if (new URL(response.url()).origin === base && response.status() >= 400) {
              errors.push(`Local HTTP ${response.status()}: ${response.url()}`);
            }
          });
          for (const route of ['/customer/dashboard.php', '/customer/trips/view.php?id=1']) {
            await page.goto(base + route);
            const button = page.locator('#te-launcher-button');
            const menu = page.locator('#te-launcher-menu');
            const ai = page.locator('#te-chat-panel');
            const label = `${role}-${width}-${route.includes('dashboard') ? 'dashboard' : 'trip'}`;
            const focused = locator => locator.evaluate(el => el === document.activeElement);
            assert.equal(await button.count(), 1, label + ' has one launcher');
            assert.equal(await page.locator('#te-chat-toggle').isVisible(), false);
            await button.click();
            if (role === 'owner') {
              assert.equal(await menu.isVisible(), true, label + ' offers AI and staff');
              assert.equal(await button.getAttribute('aria-expanded'), 'true');
              assert.equal(await page.locator('.te-launcher-option').count(), 2);
              await page.keyboard.press('Escape');
              assert.equal(await menu.isVisible(), false);
              assert.equal(await button.getAttribute('aria-expanded'), 'false');
              assert.equal(await button.evaluate(el => el === document.activeElement), true);
              // Shift+Tab out of the open menu closes it instead of leaving it over the page.
              await button.click();
              assert.equal(await focused(page.locator('.te-launcher-option').first()), true, label + ' menu focuses first option');
              await page.keyboard.press('Shift+Tab');
              assert.equal(await menu.isVisible(), false, label + ' focus leaving the launcher closes the menu');
              assert.equal(await button.getAttribute('aria-expanded'), 'false');
              await button.click();
              await page.locator('[data-open-widget="te-chat-toggle"]').click();
            }
            assert.equal(await ai.isVisible(), true, label + ' opens AI');
            const box = await ai.boundingBox();
            assert.ok(box.x >= 0 && box.x + box.width <= width + 1, label + ' AI fits viewport');
            const aiInput = page.getByRole('textbox', { name: 'Message the TravelEase assistant' });
            assert.equal(await aiInput.count(), 1, label + ' AI input has an accessible name');
            assert.equal(await focused(aiInput), true, label + ' opening AI focuses its input');
            assert.equal(await page.locator('#te-chat-messages').getAttribute('role'), 'log');
            assert.equal(await page.getByRole('region', { name: 'TravelEase assistant' }).count(), 1);
            await page.keyboard.press('Escape');
            assert.equal(await ai.isVisible(), false, label + ' Escape closes AI');
            assert.equal(await focused(button), true, label + ' Escape returns focus to Help');
            await button.click();
            if (role === 'owner') await page.locator('[data-open-widget="te-chat-toggle"]').click();
            assert.equal(await ai.isVisible(), true);
            await page.locator('#te-chat-close').click();
            assert.equal(await ai.isVisible(), false);
            assert.equal(await focused(button), true, label + ' close button returns focus to Help');
            assert.equal(await button.getAttribute('aria-expanded'), 'false', label + ' closes expanded state');
            {
              // A slow reply must not pull focus back into a panel the user already closed.
              // The stubbed request also shows what the page sends: only the trip page
              // adds its numeric trip_id, and no trip details ever leave the browser.
              let askBody = null;
              await page.route('**/chat/ask.php', async stub => {
                askBody = JSON.parse(stub.request().postData());
                await new Promise(resolve => setTimeout(resolve, 800));
                await stub.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ reply: 'Delayed stub reply' }) });
              });
              await button.click();
              if (role === 'owner') await page.locator('[data-open-widget="te-chat-toggle"]').click();
              await aiInput.fill('Local focus check');
              await aiInput.press('Enter');
              await page.locator('#te-chat-close').click();
              await page.locator('#te-chat-messages', { hasText: 'Delayed stub reply' }).waitFor({ state: 'attached' });
              await page.waitForTimeout(100);
              assert.equal(await focused(button), true, label + ' delayed reply leaves focus on Help');
              await page.unroute('**/chat/ask.php');
              const expectedKeys = route.includes('dashboard') ? ['csrf_token', 'message'] : ['csrf_token', 'message', 'trip_id'];
              assert.deepEqual(Object.keys(askBody).sort(), expectedKeys, label + ' chat request sends only expected fields');
              assert.equal(askBody.message, 'Local focus check');
              if (!route.includes('dashboard')) assert.strictEqual(askBody.trip_id, 1, label + ' trip page sends numeric trip_id');
            }
            if (role === 'owner') {
              await button.click();
              await page.locator('[data-open-widget="te-chat-toggle"]').click();
              await button.click();
              await page.locator('[data-open-widget="te-support-toggle"]').click();
              const staff = page.locator('#te-support-panel');
              assert.equal(await staff.isVisible(), true);
              assert.equal(await ai.isVisible(), false, label + ' panels never overlap');
              const staffBox = await staff.boundingBox();
              assert.ok(staffBox.x >= 0 && staffBox.x + staffBox.width <= width + 1, label + ' staff panel fits viewport');
              await page.screenshot({ path: path.join(output, label + '-staff.png') });
              const staffInput = page.getByRole('textbox', { name: 'Message support staff' });
              assert.equal(await focused(staffInput), true, label + ' opening staff chat focuses its input');
              await page.locator('#te-support-messages[role="log"]').waitFor({ state: 'attached' });
              assert.equal(await page.getByRole('region', { name: 'Support staff conversation' }).count(), 1);
              await page.keyboard.press('Escape');
              assert.equal(await staff.isVisible(), false, label + ' Escape closes staff chat');
              assert.equal(await focused(button), true, label + ' Escape returns focus to Help from staff chat');
              await button.click();
              await page.locator('[data-open-widget="te-support-toggle"]').click();
              await page.getByRole('button', { name: 'Close support conversation' }).click();
              assert.equal(await staff.isVisible(), false);
              assert.equal(await focused(button), true, label + ' staff close button returns focus to Help');
            } else {
              await button.click();
              assert.equal(await button.getAttribute('aria-expanded'), 'true');
              await button.click();
              assert.equal(await ai.isVisible(), false);
              assert.equal(await button.getAttribute('aria-expanded'), 'false');
              await page.screenshot({ path: path.join(output, label + '-closed.png') });
            }
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), label + ' no horizontal overflow');
            count++;
            console.log('PASS launcher', label);
          }
          assert.deepEqual(errors, [], 'No browser runtime errors');
        } finally {
          await context.close();
        }
      }
    }
    // Staff conversation identity: the poll endpoint is stubbed so the open
    // request can change under an open panel (same message count, the case a
    // count-only renderer missed), then close.
    {
      const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      try {
        await context.route('**/*', route => {
          if (new URL(route.request().url()).origin === base) return route.continue();
          return route.abort();
        });
        const page = await context.newPage();
        const errors = [];
        const msg = (id, sender_type, message) => ({ id, sender_type, message, created_at: '2027-01-01 00:00:00' });
        const threads = {
          old: { escalation_id: 1, messages: [msg(1, 'customer', 'Old thread message')] },
          fresh: { escalation_id: 2, messages: [msg(7, 'staff', 'New thread message')] },
          sent: { escalation_id: 2, messages: [msg(7, 'staff', 'New thread message'), msg(8, 'customer', 'Reply to new thread')] },
          closed: { escalation_id: null, messages: [] },
        };
        let phase = 'old';
        const sends = [];
        await page.route('**/customer/escalation_messages.php', stub => stub.fulfill({
          status: 200, contentType: 'application/json', body: JSON.stringify(threads[phase]) }));
        await page.route('**/customer/send_escalation_message.php', stub => {
          sends.push(JSON.parse(stub.request().postData()));
          phase = 'sent';
          return stub.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' });
        });
        await page.goto(base + '/auth/login.php');
        await page.fill('#email', 'ui-owner@example.test');
        await page.fill('#password', 'Local-Fixture-9');
        await Promise.all([page.waitForURL('**/customer/dashboard.php'), page.click('form button[type=submit]')]);
        page.on('pageerror', error => errors.push(error.message));
        const log = page.locator('#te-support-messages');
        await page.locator('#te-launcher-button').click();
        await page.locator('[data-open-widget="te-support-toggle"]').click();
        await log.getByText('Old thread message').waitFor();
        phase = 'fresh';
        await log.getByText('New thread message').waitFor({ timeout: 6000 });
        assert.equal(await log.getByText('Old thread message').count(), 0, 'old conversation history is replaced');
        assert.equal(await page.locator('#te-support-chathead').getAttribute('data-escalation-id'), '2');
        const input = page.getByRole('textbox', { name: 'Message support staff' });
        await input.fill('Reply to new thread');
        await input.press('Enter');
        await log.getByText('Reply to new thread').waitFor();
        assert.equal(String(sends[0].escalation_id), '2', 'messages go to the new conversation');
        assert.equal(await log.locator('.te-chat-message').count(), 2, 'no duplicated or mixed messages');
        phase = 'closed';
        await log.getByText('This support request has been closed.').waitFor({ timeout: 6000 });
        assert.equal(await input.isDisabled(), true, 'closed conversation disables the input');
        assert.deepEqual(errors, [], 'No browser runtime errors');
        count++;
        console.log('PASS staff conversation identity');
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
  console.log(`${count} launcher scenarios passed (Chrome; external requests blocked).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
