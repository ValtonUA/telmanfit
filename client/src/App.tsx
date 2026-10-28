import { Link, Route, Routes, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError, csrf, initialize, resetSession } from './api'
import type { FlowResult, Me } from './api'
import { AuthForm } from './components/AuthForm'
import { ClientNavigation } from './components/ClientNavigation'
import { Library, LibraryArticle } from './components/Library'
import { OnboardingFirstStep } from './components/OnboardingFirstStep'
import { MealItemRecipe } from './components/MealItemRecipe'
import { MealPlan } from './components/MealPlan'
import { ShoppingList } from './components/ShoppingList'
import { CookingAssistant } from './components/CookingAssistant'
import { SecurityPanel } from './components/SecurityPanel'
import { Today } from './components/Today'
import './App.css'

const statuses: Record<Me['client']['status'], { label: string; description: string }> = {
  pending: { label: 'Очікує початку', description: 'Акаунт створено. Платний супровід ще не активовано. Початок узгодьте з тренером.' },
  active: { label: 'Супровід активний', description: 'Ваш поточний статус супроводу — активний.' },
  paused: { label: 'Супровід призупинено', description: 'Щоб узгодити продовження, зверніться до тренера.' },
  finished: { label: 'Супровід завершено', description: 'Ваш акаунт залишається доступним.' },
}

function pageTitle(path: string): string {
  if (path.startsWith('/app/meal-plan')) return 'Раціон'
  if (path === '/app/resources' || path.startsWith('/app/resources/')) return 'Корисне'
  if (path === '/app/progress') return 'Прогрес'
  if (path === '/app/profile') return 'Профіль'
  if (path === '/app/security') return 'Безпека'
  if (path === '/app/onboarding') return 'Анкета'
  return 'Сьогодні'
}

function Profile({ me }: { me: Me }) {
  return <section className="client-profile">
    <span className="eyebrow">Ваш особистий простір</span>
    <h1>Профіль</h1>
    <div className="card"><h2>{me.user.full_name}</h2>
      <p><strong>Супровід:</strong> {statuses[me.client.status]?.label ?? 'Статус недоступний'}</p>
      <p className="muted">{statuses[me.client.status]?.description}</p>
    </div>
    <div className="card"><h2>Налаштування</h2>
      <p><Link to="/app/security">Безпека та двофакторна автентифікація</Link></p>
      <p><Link to="/app/onboarding">Моя анкета</Link></p>
    </div>
  </section>
}

function EmptySection({ section }: { section: 'Корисне' | 'Прогрес' }) {
  return <section className="client-empty">
    <span className="eyebrow">Ваш особистий простір</span>
    <h1>{section}</h1>
    <div className="card"><h2>{section === 'Корисне' ? 'Матеріали поки недоступні' : 'Показників прогресу поки немає'}</h2>
      <p className="muted">{section === 'Корисне'
        ? 'Тут з’являться статті, відео та інші матеріали після публікації тренером.'
        : 'Коли дані будуть доступні, тут можна буде переглянути ваш прогрес.'}</p>
    </div>
  </section>
}

function RecipeRoute({ onExit }: { onExit: (message: string) => void }) {
  const { itemId = '' } = useParams()
  return <MealItemRecipe key={itemId} itemId={itemId} onExit={onExit} />
}

function ArticleRoute({ onExit }: { onExit: (message: string) => void }) {
  const { id = '' } = useParams()
  return /^[1-9][0-9]*$/.test(id) ? <LibraryArticle key={id} id={id} onExit={onExit} /> : <NotFound />
}

function NotFound() {
  return <section className="card"><h1>Сторінку не знайдено</h1><p><Link to="/app">Повернутися на головну</Link></p></section>
}

export default function App() {
  const [ready, setReady] = useState(false)
  const [me, setMe] = useState<Me>()
  const [invite, setInvite] = useState<FlowResult>()
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const location = useLocation()
  const navigate = useNavigate()
  const navigateRef = useRef(navigate)
  useEffect(() => { navigateRef.current = navigate }, [navigate])
  const page = location.pathname
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    let active = true
    let generation = 0
    const load = () => {
      const current = ++generation
      initialize().then(result => {
        if (active && current === generation) {
          setMe(result.me); setInvite(result.invite)
          if (!result.me && window.location.pathname !== '/app/login' && window.location.pathname !== '/app/register') {
            void navigateRef.current('/app/login', { replace: true })
          }
        }
      }).catch(failure => { if (active && current === generation) setError((failure as Error).message) }).finally(() => { if (active && current === generation) setReady(true) })
    }
    const newInvitation = () => { setReady(false); setError(''); setMe(undefined); setInvite(undefined); void navigateRef.current(window.location.pathname + window.location.search, { replace: true }); load() }
    load()
    window.addEventListener('telmanfit-invitation', newInvitation)
    return () => { active = false; window.removeEventListener('telmanfit-invitation', newInvitation) }
  }, [])
  const exit = useCallback((message: string) => { resetSession(); try { for (const key of Object.keys(localStorage)) if (key.startsWith('tf:cooking:v1:')) localStorage.removeItem(key) } catch { /* Browser storage may be disabled. */ } setMe(undefined); setInvite(undefined); setNotice(message); void navigateRef.current('/app/login', { replace: true }) }, [])
  useEffect(() => {
    if (!me || page.startsWith('/app/meal-plan')) return
    let active = true
    const refresh = () => {
      if (document.visibilityState === 'visible') api<Me>('me').then(user => { if (active) setMe(user) }).catch(failure => { if (active && failure instanceof ApiError && failure.status === 401) { exit('Сесія завершилася. Увійдіть знову.') } })
    }
    document.addEventListener('visibilitychange', refresh)
    window.addEventListener('focus', refresh)
    return () => { active = false; document.removeEventListener('visibilitychange', refresh); window.removeEventListener('focus', refresh) }
  }, [me, page, exit])
  function login(user: Me) { resetSession(); setMe(user); setInvite(undefined); setNotice(''); void navigate('/app', { replace: true }) }

  const client = ready && Boolean(me)
  return <>
    {client ? <header className="client-header"><div className="client-header-inner">
      <div className="client-header-title"><span className="eyebrow">Ваш особистий простір</span><strong>{pageTitle(page)}</strong></div>
      <div className="client-header-actions"><Link className="client-avatar" to="/app/profile" aria-label="Відкрити профіль">
        {(me?.user.first_name ?? me?.user.full_name ?? 'К').trim().charAt(0).toUpperCase()}</Link>
        <button className="text-button" disabled={busy} onClick={async () => { setBusy(true); try { await api('auth/logout', {}); await csrf(); exit('Ви вийшли з акаунта.') } catch (failure) { setError((failure as Error).message) } finally { setBusy(false) } }}>Вийти</button>
      </div></div></header>
      : <header className="site-header"><Link className="brand" to="/app" aria-label="TelmanFit — кабінет"><span className="brand-mark" aria-hidden="true">T</span>Telman<span>Fit</span></Link><span className="header-caption">Особистий простір</span></header>}
    <main id="main-content" className={client ? 'client-main' : undefined}>
      {notice && <p role="status" className="notice">{notice}</p>}
      {me?.security.recovery_used && page !== '/app/security' && <p role="status" className="notice">Ви використали резервний код. 2FA залишається увімкненою. Невикористаних кодів: {me.security.recovery_codes_remaining}. За потреби оновіть їх у налаштуваннях безпеки.</p>}
      {error ? <section className="card auth-card"><h1>Не вдалося продовжити</h1><p role="alert" className="error">{error}</p><Link to="/app/login">Повернутися до входу</Link></section>
        : !ready ? <p role="status" className="loading">Завантажуємо ваш простір…</p>
        : me ? <Routes>
          <Route path="/app" element={<Today onExit={exit} />} />
          <Route path="/app/index.html" element={<Today onExit={exit} />} />
          <Route path="/app/meal-plan" element={<MealPlan onExit={exit} />} />
          <Route path="/app/meal-plan/items/:itemId/recipe" element={<RecipeRoute onExit={exit} />} />
          <Route path="/app/meal-plan/cooking" element={<CookingAssistant key={new URLSearchParams(location.search).get('week')} onExit={exit} />} />
          <Route path="/app/meal-plan/shopping-list" element={<ShoppingList key={location.search} onExit={exit} />} />
          <Route path="/app/resources" element={<Library onExit={exit} />} />
          <Route path="/app/resources/:id" element={<ArticleRoute onExit={exit} />} />
          <Route path="/app/progress" element={<EmptySection section="Прогрес" />} />
          <Route path="/app/profile" element={<Profile me={me} />} />
          <Route path="/app/security" element={<SecurityPanel me={me} onExit={exit} />} />
          <Route path="/app/onboarding" element={<OnboardingFirstStep />} />
          <Route path="*" element={<NotFound />} />
        </Routes>
        : <AuthForm key={page} invite={invite} onLogin={login} />}
    </main>
    {client && <ClientNavigation path={page} />}
    {!client && <footer>TelmanFit · Крок за кроком, у вашому темпі</footer>}
  </>
}
