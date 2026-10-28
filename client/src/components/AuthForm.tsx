import { Link } from 'react-router-dom'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { flushSync } from 'react-dom'
import { api, csrf } from '../api'
import type { FlowResult, Me } from '../api'
import { useCooldown } from '../useCooldown'

export function AuthForm({ invite, onLogin }: { invite?: FlowResult; onLogin: (me: Me) => void }) {
  const active = useRef(true)
  useEffect(() => { active.current = true; return () => { active.current = false } }, [])
  const registration = window.location.pathname === '/app/register'
  const [step, setStep] = useState<'email' | 'otp' | 'name' | 'mfa'>('email')
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [lastName, setLastName] = useState('')
  const [code, setCode] = useState('')
  const [method, setMethod] = useState('totp')
  const [flow, setFlow] = useState(invite?.flow_id)
  const [challenge, setChallenge] = useState<string>()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const cooldown = useCooldown()
  const [expiry, setExpiry] = useState(0)
  const [expired, setExpired] = useState(false)
  const heading = useRef<HTMLHeadingElement>(null)
  useEffect(() => { heading.current?.focus() }, [step])
  useEffect(() => {
    const erase = () => flushSync(() => { setCode(''); setEmail(''); setName(''); setLastName(''); setChallenge(undefined); setFlow(undefined) })
    window.addEventListener('pagehide', erase)
    return () => window.removeEventListener('pagehide', erase)
  }, [])
  useEffect(() => {
    const timer = window.setInterval(() => setExpired(expiry > 0 && Date.now() >= expiry), 1000)
    return () => window.clearInterval(timer)
  }, [expiry])

  async function authenticated() {
    if (!active.current) return
    setCode(''); setEmail(''); setName(''); setLastName(''); setChallenge(undefined); setFlow(undefined)
    await csrf()
    const user = await api<Me>('me')
    if (active.current) onLogin(user)
  }
  async function send() {
    const result = await api('auth/email-otp/request', { purpose: registration ? 'register' : 'login', email, ...(registration ? { flow_id: flow } : {}) })
    setChallenge(result.challenge_id); cooldown.start(result.resend_after); setExpiry(Date.now() + (result.expires_in ?? 300) * 1000); setExpired(false); setStep('otp')
  }
  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError('')
    const submittedCode = code; setCode('')
    try {
      if (step === 'email') await send()
      if (step === 'otp') {
        const result = await api('auth/email-otp/verify', { challenge_id: challenge, code: submittedCode })
        setChallenge(undefined)
        if (result.next === 'authenticated') await authenticated()
        else { setFlow(result.flow_id); setStep(result.next === 'registration_complete' ? 'name' : 'mfa') }
      }
      if (step === 'name') { await api('auth/registration/complete', { flow_id: flow, first_name: name, last_name: lastName }); await authenticated() }
      if (step === 'mfa') { await api('auth/mfa/verify', { flow_id: flow, method, code: submittedCode }); await authenticated() }
    } catch (failure) { setError(failure instanceof Error ? failure.message : 'Не вдалося виконати запит.') }
    finally { setBusy(false) }
  }
  if (registration && !invite) return <section className="card auth-card"><h1>Потрібне запрошення</h1><p>Щоб зареєструватися, відкрийте персональне посилання від тренера.</p><Link to="/app/login">Уже маєте акаунт? Увійти</Link></section>
  return <section className="card auth-card">
    <span className="eyebrow">{registration ? 'Ваш перший крок' : 'З поверненням'}</span>
    <h1 ref={heading} tabIndex={-1}>{step === 'name' ? 'Як до вас звертатися?' : step === 'mfa' ? 'Другий фактор захисту' : step === 'otp' ? 'Перевірте пошту' : registration ? 'Приєднайтеся до TelmanFit' : 'Увійдіть у свій кабінет'}</h1>
    <p className="muted">{step === 'email' ? 'Надішлемо одноразовий код на вашу електронну адресу.' : step === 'otp' ? 'Введіть 8-значний код із листа. Він діє 5 хвилин.' : step === 'mfa' ? 'Підтвердьте вхід через Authenticator або одноразовий резервний код.' : 'Ім’я відображатиметься у вашому особистому кабінеті.'}</p>
    <form onSubmit={submit} aria-busy={busy}>
      {step === 'email' && <label>Електронна пошта<input type="email" name="email" autoComplete="email" maxLength={254} required value={email} onChange={event => setEmail(event.target.value)} /></label>}
      {step === 'name' && <><label>Ваше ім’я<input name="first_name" autoComplete="given-name" maxLength={255} required value={name} onChange={event => setName(event.target.value)} /></label><label>Ваше прізвище<input name="last_name" autoComplete="family-name" maxLength={255} required value={lastName} onChange={event => setLastName(event.target.value)} /></label></>}
      {step === 'mfa' && <label>Спосіб підтвердження<select value={method} onChange={event => { setMethod(event.target.value); setCode('') }}><option value="totp">Код Authenticator</option><option value="recovery_code">Резервний код</option></select></label>}
      {(step === 'otp' || step === 'mfa') && <label>{step === 'otp' ? 'Код із листа' : method === 'totp' ? 'Код Authenticator' : 'Резервний код'}<input name="code" type="text" inputMode={step === 'otp' || method === 'totp' ? 'numeric' : 'text'} autoComplete="one-time-code" spellCheck={false} autoCapitalize="none" maxLength={step === 'otp' ? 8 : method === 'totp' ? 6 : 128} required value={code} onChange={event => setCode(event.target.value)} /></label>}
      {error && <p role="alert" className="error">{error}</p>}
      {step === 'otp' && expired && <p role="status">Строк дії коду завершився. Запросіть новий код.</p>}
      <button disabled={busy || (step === 'otp' && expired)}>{busy ? 'Зачекайте…' : step === 'email' ? 'Отримати код' : step === 'name' ? 'Завершити реєстрацію' : 'Підтвердити'}</button>
    </form>
    {step === 'otp' && <button type="button" className="secondary" disabled={busy || cooldown.remaining > 0} onClick={async () => { setBusy(true); setError(''); setCode(''); try { await send() } catch (failure) { setError((failure as Error).message) } finally { setBusy(false) } }}>{cooldown.remaining ? `Надіслати ще раз через ${cooldown.remaining} с` : 'Надіслати код ще раз'}</button>}
    {step !== 'email' && <Link className="text-link" to={registration ? '/app/register' : '/app/login'}>Почати знову</Link>}
    {registration && <Link className="text-link" to="/app/login">Уже маєте акаунт? Увійти</Link>}
    <p className="fine-print">{registration ? 'Реєстрація не активує платний супровід.' : 'Доступ до акаунта — за одноразовим кодом із пошти.'}</p>
  </section>
}
