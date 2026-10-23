import { useEffect, useState } from 'react'
import { api, csrf, initialize } from './api'
import type { FlowResult, Me } from './api'
import { AuthForm } from './components/AuthForm'
import { OnboardingFirstStep } from './components/OnboardingFirstStep'
import { SecurityPanel } from './components/SecurityPanel'
import './App.css'

const statuses: Record<Me['client']['status'], { label: string; description: string }> = {
  pending: { label: 'Очікує початку', description: 'Акаунт створено. Платний супровід ще не активовано. Початок узгодьте з тренером.' },
  active: { label: 'Супровід активний', description: 'Ваш поточний статус супроводу — активний.' },
  paused: { label: 'Супровід призупинено', description: 'Щоб узгодити продовження, зверніться до тренера.' },
  finished: { label: 'Супровід завершено', description: 'Ваш акаунт залишається доступним.' },
}

export default function App() {
  const [ready, setReady] = useState(false)
  const [me, setMe] = useState<Me>()
  const [invite, setInvite] = useState<FlowResult>()
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [page, setPage] = useState(window.location.pathname)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    let active = true
    let generation = 0
    const load = () => {
      const current = ++generation
      initialize().then(result => {
        if (active && current === generation) {
          setMe(result.me); setInvite(result.invite)
          if (!result.me && ['/app', '/app/', '/app/index.html', '/app/security', '/app/onboarding'].includes(window.location.pathname)) {
            window.history.replaceState(null, '', '/app/login'); setPage('/app/login')
          }
        }
      }).catch(failure => { if (active && current === generation) setError((failure as Error).message) }).finally(() => { if (active && current === generation) setReady(true) })
    }
    const newInvitation = () => { setReady(false); setError(''); setMe(undefined); setInvite(undefined); setPage(window.location.pathname); load() }
    load()
    window.addEventListener('telmanfit-invitation', newInvitation)
    return () => { active = false; window.removeEventListener('telmanfit-invitation', newInvitation) }
  }, [])
  useEffect(() => {
    if (!me) return
    let active = true
    const refresh = () => {
      if (document.visibilityState === 'visible') api<Me>('me').then(user => { if (active) setMe(user) }).catch(() => { if (active) { setMe(undefined); setNotice('Сесія завершилася. Увійдіть знову.'); window.history.replaceState(null, '', '/app/login'); setPage('/app/login') } })
    }
    document.addEventListener('visibilitychange', refresh)
    return () => { active = false; document.removeEventListener('visibilitychange', refresh) }
  }, [me])
  function login(user: Me) { setMe(user); setInvite(undefined); setNotice(''); window.history.replaceState(null, '', '/app'); setPage('/app') }
  function exit(message: string) { setMe(undefined); setInvite(undefined); setNotice(message); window.history.replaceState(null, '', '/app/login'); setPage('/app/login') }
  return <>
    <header className="site-header"><a className="brand" href="/app" aria-label="TelmanFit — кабінет"><span className="brand-mark" aria-hidden="true">T</span>Telman<span>Fit</span></a><span className="header-caption">Особистий простір</span>{me && <button className="text-button" disabled={busy} onClick={async () => { setBusy(true); try { await api('auth/logout', {}); await csrf(); exit('Ви вийшли з акаунта.') } catch (failure) { setError((failure as Error).message) } finally { setBusy(false) } }}>Вийти</button>}</header>
    <main id="main-content">
      {notice && <p role="status" className="notice">{notice}</p>}
      {me?.security.recovery_used && page !== '/app/security' && <p role="status" className="notice">Ви використали резервний код. 2FA залишається увімкненою. Невикористаних кодів: {me.security.recovery_codes_remaining}. За потреби оновіть їх у налаштуваннях безпеки.</p>}
      {error ? <section className="card auth-card"><h1>Не вдалося продовжити</h1><p role="alert" className="error">{error}</p><a href="/app/login">Повернутися до входу</a></section>
        : !ready ? <p role="status" className="loading">Завантажуємо ваш простір…</p>
        : me && page === '/app/security' ? <SecurityPanel me={me} onExit={exit} />
        : me && page === '/app/onboarding' ? <OnboardingFirstStep />
        : me ? <div className="dashboard"><span className="eyebrow">Ваш особистий кабінет</span><h1>Вітаємо, {me.user.first_name ?? me.user.full_name}</h1><p className="muted intro">Ваш шлях починається з турботи про себе.</p><section className="card"><span className="eyebrow">Статус супроводу</span><h2>{statuses[me.client.status]?.label ?? 'Статус недоступний'}</h2><p>{statuses[me.client.status]?.description}</p></section><section className="card onboarding-preview"><div><h2>Ваша початкова анкета</h2><p>Три запитання з вашої анкети. Відповіді зберігаються в особистому кабінеті.</p></div><a className="button" href="/app/onboarding">Заповнити анкету</a></section><section className="card security-preview"><div><h2>Спокій за свій акаунт</h2><p>Другий фактор, електронна пошта та ваші пристрої.</p></div><a className="button" href="/app/security">Налаштування безпеки</a></section></div>
        : <AuthForm key={page} invite={invite} onLogin={login} />}
    </main>
    <footer>TelmanFit · Крок за кроком, у вашому темпі</footer>
  </>
}
