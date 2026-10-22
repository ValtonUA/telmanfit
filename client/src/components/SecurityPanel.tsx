import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { flushSync } from 'react-dom'
import { api, ApiError, csrf } from '../api'
import type { FlowResult, Me, SessionInfo } from '../api'
import { useCooldown } from '../useCooldown'

type Action = 'enable_factor' | 'replace_factor' | 'disable_factor' | 'rotate_recovery' | 'revoke_session' | 'logout_all' | 'email_change'
const labels: Record<Action, string> = {
  enable_factor: 'Підключити Authenticator', replace_factor: 'Замінити Authenticator', disable_factor: 'Вимкнути 2FA',
  rotate_recovery: 'Оновити резервні коди', revoke_session: 'Відкликати сесію', logout_all: 'Вийти з усіх пристроїв', email_change: 'Змінити електронну пошту',
}
type Enrollment = { flow_id: string; secret: string; qr_image: string }

export function SecurityPanel({ me, onExit }: { me: Me; onExit: (message: string) => void }) {
  const [sessions, setSessions] = useState<SessionInfo[]>([])
  const [action, setAction] = useState<Action>()
  const [step, setStep] = useState<'start' | 'otp' | 'mfa' | 'ready' | 'enroll' | 'codes' | 'email' | 'email-otp'>('start')
  const [flow, setFlow] = useState<FlowResult>({})
  const [target, setTarget] = useState('')
  const [code, setCode] = useState('')
  const [email, setEmail] = useState('')
  const [method, setMethod] = useState('totp')
  const [enrollment, setEnrollment] = useState<Enrollment>()
  const [codes, setCodes] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const cooldown = useCooldown()
  const heading = useRef<HTMLHeadingElement>(null)
  useEffect(() => { heading.current?.focus() }, [action, step])

  useEffect(() => {
    let active = true
    api<{ sessions: SessionInfo[] }>('auth/security/sessions').then(result => { if (active) setSessions(result.sessions) }).catch(failure => { if (active) setError((failure as Error).message) })
    return () => { active = false }
  }, [])
  useEffect(() => {
    const erase = () => flushSync(() => { setEnrollment(undefined); setCodes([]); setCode(''); setFlow({}); setEmail(''); setAction(undefined); setStep('start') })
    const clear = () => {
      if (document.visibilityState === 'hidden') erase()
    }
    document.addEventListener('visibilitychange', clear)
    window.addEventListener('pagehide', erase)
    return () => { document.removeEventListener('visibilitychange', clear); window.removeEventListener('pagehide', erase) }
  }, [])

  function reset() { setAction(undefined); setStep('start'); setFlow({}); setCode(''); setEmail(''); setEnrollment(undefined); setCodes([]); setError('') }
  async function run(operation: () => Promise<void>) {
    setBusy(true); setError('')
    try { await operation() } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) { reset(); onExit(failure.message) }
      else setError((failure as Error).message)
    } finally { setBusy(false) }
  }
  async function requestCode() {
    const result = await api('auth/security/reauth/request', { action })
    setFlow(result); setStep('otp'); cooldown.start(result.resend_after)
  }
  async function accepted(result: FlowResult) {
    setFlow(result)
    if (result.next === 'mfa_required') setStep('mfa')
    else { await csrf(); setStep('ready') }
  }
  async function perform() {
    const body = { proof_id: flow.flow_id }
    if (action === 'enable_factor' || action === 'replace_factor') {
      const result = await api<Enrollment>('auth/security/mfa/enroll', { ...body, replace: action === 'replace_factor' })
      setEnrollment(result); setFlow({}); setStep('enroll')
    } else if (action === 'rotate_recovery') {
      const result = await api<{ recovery_codes: string[] }>('auth/security/mfa/recovery', body)
      await csrf(); setCodes(result.recovery_codes); setFlow({}); setSessions([]); setStep('codes')
    } else if (action === 'email_change') setStep('email')
    else if (action === 'revoke_session') {
      await api('auth/security/sessions/revoke', { ...body, handle: target }); reset()
      setSessions((await api<{ sessions: SessionInfo[] }>('auth/security/sessions')).sessions)
    } else {
      await api(action === 'disable_factor' ? 'auth/security/mfa/disable' : 'auth/security/logout-all', body)
      await csrf(); reset(); onExit(action === 'disable_factor' ? '2FA вимкнено. Увійдіть знову.' : 'Усі сесії відкликано. Увійдіть знову.')
    }
  }
  async function submit(event: FormEvent) {
    event.preventDefault()
    const submittedCode = code; setCode('')
    await run(async () => {
      if (step === 'start') await requestCode()
      else if (step === 'otp') await accepted(await api('auth/security/reauth/verify', { challenge_id: flow.challenge_id, code: submittedCode }))
      else if (step === 'mfa') await accepted(await api('auth/security/reauth/mfa', { flow_id: flow.flow_id, method, code: submittedCode }))
      else if (step === 'ready') await perform()
      else if (step === 'enroll') {
        const result = await api<{ recovery_codes: string[] }>('auth/security/mfa/confirm', { flow_id: enrollment?.flow_id, code: submittedCode })
        setEnrollment(undefined); setCodes(result.recovery_codes); setFlow({}); setSessions([]); setStep('codes'); await csrf()
      } else if (step === 'email') {
        const result = await api('auth/security/email/request', { proof_id: flow.flow_id, email })
        setFlow(result); setStep('email-otp'); cooldown.start(result.resend_after)
      } else if (step === 'email-otp') {
        await api('auth/security/email/confirm', { challenge_id: flow.challenge_id, code: submittedCode }); await csrf(); reset(); onExit('Адресу змінено. Увійдіть із новою поштою.')
      }
    })
  }
  function selectAction(value: Action, handle = '') { reset(); setAction(value); setTarget(handle) }
  const codeStep = ['otp', 'mfa', 'enroll', 'email-otp'].includes(step)
  const codeLabel = step === 'mfa' && method === 'recovery_code' ? 'Резервний код' : step === 'mfa' || step === 'enroll' ? 'Код Authenticator' : 'Код із листа'
  return <div className="security-layout">
    <div><span className="eyebrow">Ваш акаунт</span><h1>Безпека та вхід</h1><p className="muted">Керуйте підтвердженням входу та доступом зі своїх пристроїв.</p></div>
    {error && <p role="alert" className="error">{error}</p>}
    {action ? <section className="card" aria-labelledby="action-title">
      <h2 id="action-title" ref={heading} tabIndex={-1}>{labels[action]}</h2>
      {step === 'codes' ? <>
        <p>Збережіть резервні коди в надійному місці поза браузером. Кожен код одноразовий. Після закриття екрана або приховування вкладки побачити їх повторно неможливо.</p>
        <div className="recovery-codes" aria-label="Резервні коди">{codes.map(item => <code key={item}>{item}</code>)}</div>
        <p>Усі попередні сесії відкликано. Потрібен новий повний вхід.</p>
        <button onClick={() => { reset(); onExit('Коди збережено. Увійдіть знову.') }}>Я зберіг / зберегла коди</button>
      </> : <form onSubmit={submit} aria-busy={busy}>
        {step === 'start' && <p>Для цієї дії потрібен новий код із вашої поточної пошти{me.security.mfa_enabled ? ' та чинний Authenticator або резервний код' : ''}. Підтвердження діє до 5 хвилин і лише один раз.</p>}
        {step === 'otp' && <p>Введіть новий 8-значний код із поточної пошти.</p>}
        {step === 'mfa' && <><p>Пошта підтверджена. Тепер підтвердьте другий фактор. Якщо поточний код уже використано, дочекайтеся наступного.</p><label>Спосіб підтвердження<select value={method} onChange={event => { setMethod(event.target.value); setCode('') }}><option value="totp">Код Authenticator</option><option value="recovery_code">Резервний код</option></select></label></>}
        {step === 'ready' && <p>Вхід підтверджено. {action === 'email_change' ? 'Далі підтвердіть нову пошту.' : action === 'enable_factor' || action === 'replace_factor' ? 'Далі налаштуйте новий Authenticator.' : 'Підтвердьте виконання дії. Зміна MFA, кодів або вихід з усіх пристроїв завершить поточний сеанс.'}</p>}
        {step === 'enroll' && enrollment && <div className="enrollment"><p>Відскануйте QR-код у застосунку Authenticator або введіть ключ вручну. Потім введіть новий 6-значний код. До підтвердження новий фактор не діє.</p><img src={enrollment.qr_image} width="240" height="240" alt="QR-код для підключення Authenticator" /><p>Ключ для ручного введення</p><code className="manual-key">{enrollment.secret}</code></div>}
        {step === 'email' && <label>Нова електронна пошта<input type="email" autoComplete="email" required maxLength={254} value={email} onChange={event => setEmail(event.target.value)} /></label>}
        {step === 'email-otp' && <p>Введіть 8-значний код із нової пошти. Після підтвердження всі пристрої вийдуть з акаунта.</p>}
        {codeStep && <label>{codeLabel}<input type="text" name="code" required autoComplete="one-time-code" spellCheck={false} autoCapitalize="none" inputMode={codeLabel === 'Резервний код' ? 'text' : 'numeric'} maxLength={codeLabel === 'Резервний код' ? 128 : codeLabel === 'Код із листа' ? 8 : 6} value={code} onChange={event => setCode(event.target.value)} /></label>}
        <button disabled={busy}>{busy ? 'Зачекайте…' : step === 'start' ? 'Отримати код підтвердження' : step === 'ready' ? 'Виконати дію' : step === 'email' ? 'Надіслати код на нову пошту' : 'Підтвердити'}</button>
        {step === 'otp' && <button type="button" className="secondary" disabled={busy || cooldown.remaining > 0} onClick={() => run(requestCode)}>{cooldown.remaining ? `Повторити через ${cooldown.remaining} с` : 'Надіслати ще раз'}</button>}
        {step === 'email-otp' && <button type="button" className="secondary" disabled={busy || cooldown.remaining > 0 || !flow.flow_id} onClick={() => run(async () => { const result = await api('auth/security/email/request', { email, flow_id: flow.flow_id }); setFlow(result); cooldown.start(result.resend_after) })}>{cooldown.remaining ? `Повторити через ${cooldown.remaining} с` : 'Надіслати ще раз'}</button>}
        <button type="button" className="text-button" disabled={busy} onClick={reset}>Скасувати та очистити форму</button>
      </form>}
    </section> : <>
      <section className="card"><div className="section-heading"><h2>Двофакторна автентифікація</h2><span className="badge">{me.security.mfa_enabled ? 'Увімкнено' : 'Не підключено'}</span></div><p>Authenticator додає захист після коду з пошти. Резервний код замінює другий фактор лише після підтвердження пошти.</p><div className="button-group">{me.security.mfa_enabled ? <><button onClick={() => selectAction('rotate_recovery')}>Оновити резервні коди</button><button className="secondary" onClick={() => selectAction('replace_factor')}>Замінити Authenticator</button><button className="danger" onClick={() => selectAction('disable_factor')}>Вимкнути 2FA</button></> : <button onClick={() => selectAction('enable_factor')}>Підключити Authenticator</button>}</div></section>
      <section className="card"><h2>Електронна пошта</h2><p>Зміна потребує підтвердження поточного входу й нової адреси та завершує всі сесії.</p><button className="secondary" onClick={() => selectAction('email_change')}>Змінити електронну пошту</button></section>
      <section className="card"><h2>Ваші сесії</h2><ul className="session-list">{sessions.map(session => <li key={session.handle}><div><strong>{session.current ? 'Цей пристрій' : 'Інший пристрій'}</strong><p className="muted">Остання активність: <time dateTime={session.last_active_at}>{new Date(session.last_active_at).toLocaleString('uk-UA')}</time></p></div>{!session.current && <button className="secondary" onClick={() => selectAction('revoke_session', session.handle)}>Відкликати</button>}</li>)}</ul><button className="danger" onClick={() => selectAction('logout_all')}>Вийти з усіх пристроїв</button></section>
    </>}
    <a href="/app" className="text-link">← До кабінету</a>
  </div>
}
