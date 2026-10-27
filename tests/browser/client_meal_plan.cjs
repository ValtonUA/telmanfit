/* Chromium + справжня React/API/ClientSession; тільки disposable synthetic fixture. */
const { chromium, expect } = require('playwright/test')
const { execFileSync } = require('node:child_process')
const fs = require('node:fs')
const path = require('node:path')
const base = process.env.CLIENT_MENU_BASE || 'http://127.0.0.1:18052'
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw Error('Потрібен локальний synthetic HTTP server')
const output = path.resolve('docs/evidence/client-meal-plan')
fs.mkdirSync(output, { recursive: true })
function php(file, ...args) {
  const command = process.env.CLIENT_MENU_DOCKER ? 'docker' : 'php'
  const prefix = process.env.CLIENT_MENU_DOCKER ? ['exec', '-e', 'AUTH4_SYNTHETIC_MAILBOX=/tmp/telmanfit-auth4-t52/mailbox', process.env.CLIENT_MENU_DOCKER, 'php'] : []
  return execFileSync(command, [...prefix, file, ...args], { encoding: 'utf8' }).trim()
}
function fixture(mode) {
  if (php('tests/browser/client-meal-plan-fixture.php', mode) !== 'T5.2 fixture action complete') {
    throw Error('Synthetic fixture action failed (details suppressed)')
  }
}
const checks = []
;(async () => {
  fixture('reset')
  php('tests/browser/auth5-fixture.php', 'window', 't52-browser@example.invalid')
  const browser = await chromium.launch({ headless: true, ...(process.env.CLIENT_MENU_CHANNEL ? { channel: process.env.CLIENT_MENU_CHANNEL } : {}) })
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' })
    const page = await context.newPage()
    page.setDefaultTimeout(15000)
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    await page.route('https://images.example.com/**', route => route.fulfill({ contentType: 'image/png',
      body: fs.readFileSync('client/src/assets/hero.png') }))
    const login = async () => {
      await page.goto(base + '/app/login')
      await page.getByLabel('Електронна пошта', { exact: true }).fill('t52-browser@example.invalid')
      await page.getByRole('button', { name: 'Отримати код', exact: true }).click()
      await expect(page.getByLabel('Код із листа', { exact: true })).toBeVisible()
      const code = php('tests/browser/auth5-fixture.php', 'mail', 't52-browser@example.invalid')
      await page.getByLabel('Код із листа', { exact: true }).fill(code)
      await page.getByRole('button', { name: 'Підтвердити', exact: true }).click()
      await expect(page.getByRole('link', { name: 'Переглянути раціон' })).toBeVisible()
    }
    await login()
    let release
    const hold = new Promise(resolve => { release = resolve })
    await page.route('**/api/meal-plan/days*', async route => { await hold; await route.continue() })
    await page.getByRole('link', { name: 'Переглянути раціон' }).click()
    await expect(page.getByRole('heading', { name: 'Завантажуємо раціон…' })).toBeVisible()
    release()
    await expect(page.locator('.plan-title')).toHaveText('Синтетичний раціон — тест T5.2')
    await page.unroute('**/api/meal-plan/days*')
    const response = await context.request.get(base + '/api/meal-plan/days')
    expect(response.status()).toBe(200)
    expect(response.headers()['cache-control']).toBe('no-store, private')
    const model = await response.json()
    expect(model.days[0].meals[0].items[1].image_url).toBe('https://images.example.com/t52.jpg')
    expect(model.days.length).toBeGreaterThan(0)
    await expect(page.locator('.day-heading .eyebrow')).toHaveText(`День ${model.days[0].day_number}`)
    expect(model.days[0].date).toBe(model.assignment.start_date)
    expect(model.days[0].meals[0].items[1].recipe_version_id).toBeTruthy()
    await expect(page.locator('.dish-quantity').first()).toHaveText('125,5 г')
    await expect(page.locator('.dish-quantity').nth(1)).toHaveText('0,5 порції')
    await expect(page.locator('a[href*="recipe"], a[href*="admin"]')).toHaveCount(0)
    await expect(page.locator('.recipe-later').first()).toHaveText('Перегляд рецепта буде доступний пізніше')
    checks.push('real API / cookie ClientSession / server quantities / exact recipe model / loading')
    const picker = page.getByRole('combobox', { name: 'Тиждень раціону' })
    await picker.selectOption('2')
    await expect(page.getByRole('button', { name: /День 8/ })).toBeVisible()
    await page.getByRole('button', { name: /День 9/ }).click()
    await expect(page.locator('.day-heading .eyebrow')).toHaveText('День 9')
    await picker.focus()
    await page.keyboard.press('ArrowUp')
    await page.keyboard.press('Enter')
    await expect(picker).toHaveValue('1')
    await page.locator('.plan-days button').first().focus()
    await page.keyboard.press('Enter')
    expect(await page.evaluate(() => document.activeElement?.tagName)).toBe('BUTTON')
    checks.push('week/day navigation / keyboard / assignment calendar')
    await expect(page.locator('img.dish-image').first()).toBeVisible()
    expect(await page.locator('img.dish-image').first().evaluate(img => img.naturalWidth)).toBeGreaterThan(0)
    await page.unroute('https://images.example.com/**')
    await page.route('https://images.example.com/**', route => route.abort())
    await page.reload()
    await expect(page.locator('.plan-title')).toBeVisible()
    await expect(page.locator('img.dish-image')).toHaveCount(0)
    await expect(page.locator('.photo-placeholder').first()).toBeVisible()
    checks.push('nullable and broken logical cover / no step images')
    for (const theme of ['light', 'dark']) {
      await page.emulateMedia({ colorScheme: theme })
      for (const width of [1440, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 })
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
        await page.screenshot({ path: path.join(output, `meal-plan-${theme}-${width}.png`), fullPage: true })
        const contrast = await page.locator('.dish-copy h3').first().evaluate(el => {
          const luminance = css => {
            const rgb = css.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => { const s = v / 255; return s <= .04045 ? s / 12.92 : ((s + .055) / 1.055) ** 2.4 })
            return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722
          }
          let parent = el
          while (getComputedStyle(parent).backgroundColor === 'rgba(0, 0, 0, 0)') parent = parent.parentElement
          const a = luminance(getComputedStyle(el).color), b = luminance(getComputedStyle(parent).backgroundColor)
          return (Math.max(a, b) + .05) / (Math.min(a, b) + .05)
        })
        expect(contrast).toBeGreaterThanOrEqual(4.5)
      }
    }
    checks.push('1440 / 390 / 320 light + dark / no horizontal overflow / text contrast >= 4.5')
    await page.route('**/api/meal-plan/days*', route => route.fulfill({ status: 503, contentType: 'application/json', body: '{"code":"auth_unavailable"}' }))
    await page.getByRole('button', { name: 'Оновити', exact: true }).click()
    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.locator('.dish')).toHaveCount(0)
    await page.unroute('**/api/meal-plan/days*')
    await page.getByRole('button', { name: 'Спробувати ще раз', exact: true }).click()
    await expect(page.locator('.dish').first()).toBeVisible()
    checks.push('API error clears stale dishes / real retry')
    // Неможливий для чинної published SQL-схеми порожній день: лише UI resilience response.
    await page.route('**/api/meal-plan/days*', async route => {
      const real = await route.fetch(); const body = await real.json()
      body.days = [{ ...body.days[0], meals: [] }]
      await route.fulfill({ response: real, json: body })
    })
    await page.getByRole('button', { name: 'Оновити', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Для цього дня меню недоступне' })).toBeVisible()
    await page.unroute('**/api/meal-plan/days*')
    checks.push('unavailable selected day (synthetic UI fault injection)')
    await page.getByRole('button', { name: 'Оновити', exact: true }).click()
    await expect(picker).toBeVisible()
    await picker.selectOption('2')
    await expect(page.getByRole('button', { name: /День 8/ })).toBeVisible()
    fixture('replace')
    await page.getByRole('button', { name: 'Оновити', exact: true }).click()
    await expect(page.locator('.dish').first()).toBeVisible()
    await expect(page.locator('.day-heading .eyebrow')).toHaveText('День 1')
    await expect(picker).toHaveValue('1')
    checks.push('assignment replacement while week 2 is open resets unavailable week safely')
    fixture('empty')
    await page.getByRole('button', { name: 'Оновити', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Поки немає призначеного раціону' })).toBeVisible()
    fixture('replace')
    await page.getByRole('button', { name: 'Оновити', exact: true }).click()
    await expect(page.locator('.notice[role="status"]').first()).toContainText('Призначення змінилося')
    await expect(page.locator('.dish').first()).toBeVisible()
    await expect(page.locator('.day-heading .eyebrow')).toHaveText('День 1')
    await expect(page.getByRole('button', { name: 'Оновити', exact: true })).toBeEnabled()
    fixture('revoke')
    expect((await context.request.get(base + '/api/meal-plan/days')).status()).toBe(401)
    const rejected = page.waitForResponse(r => r.url().includes('/api/meal-plan/days') && r.request().method() === 'GET')
    await page.getByRole('button', { name: 'Оновити', exact: true }).click()
    expect((await rejected).status()).toBe(401)
    await expect(page).toHaveURL(base + '/app/login')
    await expect(page.getByRole('status')).toContainText('Сесія завершилася')
    await expect(page.locator('.dish')).toHaveCount(0)
    checks.push('real empty assignment / replacement refresh / revoked session clears private UI')
    expect(errors).toEqual([])
    fs.writeFileSync(path.join(output, 'browser-checks.json'), JSON.stringify({ checks, result: 'PASS', browser: await browser.version() }, null, 2) + '\n')
    console.log(JSON.stringify({ result: 'PASS', checks }, null, 2))
  } finally { await browser.close() }
})().catch(e => { console.error(e); process.exitCode = 1 })
