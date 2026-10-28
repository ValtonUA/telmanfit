export type Me = {
  user: { id: string; first_name: string | null; last_name: string | null; full_name: string }
  client: { id: string; status: 'pending' | 'active' | 'paused' | 'finished' }
  security: { mfa_enabled: boolean; methods: string[]; recovery_codes_remaining: number; recovery_used: boolean }
}
export type FlowResult = {
  next?: string
  challenge_id?: string
  flow_id?: string
  resend_after?: number
  expires_in?: number
}
export type SessionInfo = { handle: string; current: boolean; authenticated_at: string; last_active_at: string }

export class ApiError extends Error {
  status: number
  constructor(status: number, code?: string) {
    const messages: Record<number, string> = {
      401: 'Сесія завершилася. Увійдіть знову.',
      403: 'Підтвердження недійсне або прострочене. Почніть підтвердження знову.',
      404: 'Вхід зараз недоступний.',
      409: 'Цю адресу не можна використати. Акаунти не об’єднуються.',
      419: 'Захист форми оновлено. Перевірте дані й натисніть кнопку ще раз.',
      422: 'Перевірте дані. Код може бути неправильним, використаним або простроченим. Для Authenticator дочекайтеся нового коду.',
      429: 'Забагато спроб. Зачекайте 15 хвилин і спробуйте знову.',
      503: 'Сервіс тимчасово недоступний. Спробуйте пізніше.',
    }
    super(code === 'factor_disable_unavailable' ? 'Вимкнення 2FA поки недоступне. Можна замінити Authenticator або оновити резервні коди.' : code === 'invitation_unavailable' ? 'Запрошення недоступне: його використано, відкликано або строк дії завершився. Зверніться до тренера.' : messages[status] ?? 'Не вдалося виконати запит. Перевірте з’єднання та спробуйте ще раз.')
    this.status = status
  }
}

export async function csrf(): Promise<void> {
  const response = await fetch('/api/auth/csrf', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
  if (!response.ok) throw new ApiError(response.status)
}

export async function api<T = FlowResult>(path: string, data?: Record<string, unknown>): Promise<T> {
  const cookie = document.cookie.split('; ').find(item => item.startsWith('TELMANFIT-CLIENT-XSRF='))?.split('=').slice(1).join('=')
  const response = await fetch(`/api/${path}`, {
    method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: { Accept: 'application/json', ...(data === undefined ? {} : { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(cookie ?? '') }) },
    body: data === undefined ? undefined : JSON.stringify(data),
  })
  const body = response.status === 204 ? {} : await response.json().catch(() => ({}))
  if (!response.ok) {
    if (response.status === 419) await csrf()
    throw new ApiError(response.status, body.code)
  }
  return body as T
}

// Fragment читається рівно один раз до монтування React, включно зі StrictMode.
function takeFragment(): string | undefined {
  if (!window.location.hash) return undefined
  const token = new URLSearchParams(window.location.hash.slice(1)).get('invite') ?? undefined
  window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search)
  return token
}
let invitation = takeFragment()
let initialization: Promise<{ me?: Me; invite?: FlowResult }> | undefined
window.addEventListener('hashchange', () => {
  const token = takeFragment()
  if (token && window.location.pathname === '/app/register') {
    invitation = token; initialization = undefined
    window.dispatchEvent(new Event('telmanfit-invitation'))
  }
})
export function resetSession(): void {
  initialization = undefined
  invitation = undefined
}
export function initialize(): Promise<{ me?: Me; invite?: FlowResult }> {
  if (!initialization) initialization = (async () => {
    const token = invitation
    invitation = undefined
    await csrf()
    if (token && window.location.pathname === '/app/register') {
      return { invite: await api('auth/invitations/exchange', { token }) }
    }
    if (window.location.pathname === '/app' || window.location.pathname.startsWith('/app/') && !['/app/login', '/app/register'].includes(window.location.pathname)) {
      try { return { me: await api<Me>('me') } } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 401) throw error
      }
    }
    return {}
  })()
  return initialization
}
