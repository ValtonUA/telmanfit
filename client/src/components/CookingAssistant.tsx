import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ApiError } from '../api'
import { getCookingChoices, getCookingSession } from '../cookingApi'
import type { CookingChoices, CookingDetail, CookingStage } from '../cookingApi'
import './CookingAssistant.css'

type TimerState = { endsAt: number | null; remaining: number; running: boolean }
type Progress = { stage: number; started?: boolean; timers: Record<string, TimerState>; expiresAt: string }
type WakeLockSentinel = { release: () => Promise<void>; released: boolean; addEventListener: (type: 'release', listener: () => void) => void }
type WakeLockNavigator = Navigator & { wakeLock?: { request: (type: 'screen') => Promise<WakeLockSentinel> } }
const numberText = (value: string) => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 2 }).format(Number(value))
const storageKey = (scope: CookingDetail['progress_scope']) => `tf:cooking:v1:${scope.client_id}:${scope.assignment_id}:${scope.week}:${scope.date}:${scope.cooking_version_id}:${scope.session_id}`
const removeProgress = (key: string) => { try { localStorage.removeItem(key) } catch { /* Storage can be disabled. */ } }

export function CookingAssistant({ onExit }: { onExit: (message: string) => void }) {
  const week = Number(new URLSearchParams(window.location.search).get('week'))
  const [choices, setChoices] = useState<CookingChoices>()
  const [detail, setDetail] = useState<CookingDetail>()
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(true)
  const [stageIndex, setStageIndex] = useState(0)
  const [playerStarted, setPlayerStarted] = useState(false)
  const [timers, setTimers] = useState<Record<string, TimerState>>({})
  const [hydratedScope, setHydratedScope] = useState<string>()
  const [now, setNow] = useState(Date.now())
  const [wakeEnabled, setWakeEnabled] = useState(false)
  const [wakeState, setWakeState] = useState<'unsupported' | 'off' | 'active' | 'lost'>('off')
  const wakeLock = useRef<WakeLockSentinel | undefined>(undefined)
  const autoOpened = useRef(false)
  const [gallery, setGallery] = useState<string[]>()
  const [galleryIndex, setGalleryIndex] = useState(0)
  const supported = typeof (navigator as WakeLockNavigator).wakeLock?.request === 'function'
  const activeTimers = useMemo(() => Object.entries(timers).filter(([, timer]) => timer.running), [timers])
  const openSession = useCallback(async (version: string, session: string, mode: number) => {
    setBusy(true); setError('')
    window.history.replaceState(null, '', `/app/meal-plan/cooking?week=${week}&version=${encodeURIComponent(version)}&session=${encodeURIComponent(session)}&mode=${mode}`)
    try { setDetail(await getCookingSession(version, session, week, mode)) }
    catch (failure) { if (failure instanceof ApiError && failure.status === 401) onExit('Сесія завершилася. Увійдіть знову.'); else setError('Ця кухонна сесія більше недоступна. Оновіть список і виберіть актуальну опубліковану версію.') }
    finally { setBusy(false) }
  }, [week, onExit])

  useEffect(() => {
    let active = true
    if (!Number.isInteger(week) || week < 1) { setError('У посиланні бракує номера тижня раціону.'); setBusy(false); return }
    getCookingChoices(week).then(value => { if (active) setChoices(value) }).catch(failure => {
      if (active) { if (failure instanceof ApiError && failure.status === 401) onExit('Сесія завершилася. Увійдіть знову.'); else setError('Помічник приготування зараз недоступний. Оновіть сторінку або зверніться до тренера.') }
    }).finally(() => { if (active) setBusy(false) })
    return () => { active = false }
  }, [week, onExit])
  useEffect(() => {
    if (!choices || detail || autoOpened.current) return
    const params = new URLSearchParams(window.location.search)
    const version = params.get('version'); const session = params.get('session'); const mode = Number(params.get('mode'))
    if (version && session && [1, 2, 7].includes(mode)) { autoOpened.current = true; void openSession(version, session, mode) }
  }, [choices, detail, openSession])

  const releaseWakeLock = useCallback(async () => {
    if (wakeLock.current && !wakeLock.current.released) await wakeLock.current.release().catch(() => undefined)
    wakeLock.current = undefined
    setWakeState(wakeEnabled ? 'lost' : (supported ? 'off' : 'unsupported'))
  }, [wakeEnabled, supported])
  const requestWakeLock = useCallback(async () => {
    if (!wakeEnabled) return
    const api = (navigator as WakeLockNavigator).wakeLock
    if (!api) { setWakeState('unsupported'); return }
    try {
      const lock = await api.request('screen'); wakeLock.current = lock; setWakeState('active')
      lock.addEventListener('release', () => { if (wakeLock.current === lock) { wakeLock.current = undefined; setWakeState('lost') } })
    } catch { setWakeState('lost') }
  }, [wakeEnabled])
  useEffect(() => {
    if (wakeEnabled) void requestWakeLock(); else { void releaseWakeLock(); setWakeState(supported ? 'off' : 'unsupported') }
    return () => { if (wakeLock.current && !wakeLock.current.released) void wakeLock.current.release().catch(() => undefined) }
  }, [wakeEnabled, requestWakeLock, releaseWakeLock, supported])
  useEffect(() => {
    const visible = () => { if (document.visibilityState === 'visible' && wakeEnabled) void requestWakeLock(); else if (document.visibilityState !== 'visible') void releaseWakeLock() }
    document.addEventListener('visibilitychange', visible)
    return () => document.removeEventListener('visibilitychange', visible)
  }, [wakeEnabled, requestWakeLock, releaseWakeLock])
  useEffect(() => {
    const ticker = window.setInterval(() => {
      const tick = Date.now(); setNow(tick)
      setTimers(current => {
        let changed = false
        const next = { ...current }
        for (const [key, timer] of Object.entries(current)) {
          if (timer.running && (timer.endsAt ?? tick) <= tick) {
            next[key] = { remaining: 0, endsAt: null, running: false }; changed = true
          }
        }
        return changed ? next : current
      })
    }, 250)
    return () => window.clearInterval(ticker)
  }, [])

  useEffect(() => {
    if (!detail) return
    const key = storageKey(detail.progress_scope)
    setHydratedScope(undefined); setStageIndex(0); setTimers({}); setPlayerStarted(false)
    try {
      const raw = localStorage.getItem(key)
      if (raw) {
        const saved = JSON.parse(raw) as Partial<Progress>
        if (saved.expiresAt === detail.progress_scope.expires_at && Date.parse(saved.expiresAt) > Date.now()) {
          const stage = Number.isInteger(saved.stage) ? Math.max(0, Math.min(detail.session.stages.length - 1, saved.stage as number)) : 0
          const validTimers = Object.fromEntries(Object.entries(saved.timers ?? {}).filter(([timerId, value]) => {
            if (!/^\d{1,2}:\d{1,3}$/.test(timerId) || !value || typeof value !== 'object') return false
            const timer = value as Partial<TimerState>
            const validEnd = timer.running ? typeof timer.endsAt === 'number' && Number.isFinite(timer.endsAt) : timer.endsAt === null
            return typeof timer.running === 'boolean' && typeof timer.remaining === 'number' && Number.isFinite(timer.remaining) && timer.remaining >= 0
              && timer.remaining <= 604800 && validEnd && (!timer.running || (typeof timer.endsAt === 'number' && timer.endsAt <= Date.now() + 604800000))
          })) as Record<string, TimerState>
          setStageIndex(stage); setTimers(validTimers); setPlayerStarted(saved.started === true)
        } else removeProgress(key)
      }
    } catch { removeProgress(key) }
    setHydratedScope(key)
  }, [detail])
  useEffect(() => {
    if (!detail) return
    const key = storageKey(detail.progress_scope)
    if (hydratedScope !== key) return
    try { localStorage.setItem(key, JSON.stringify({ stage: stageIndex, started: playerStarted, timers, expiresAt: detail.progress_scope.expires_at } satisfies Progress)) } catch { /* Storage can be disabled or full; the session remains usable. */ }
  }, [detail, hydratedScope, stageIndex, playerStarted, timers])

  useEffect(() => {
    if (!detail) return
    let active = true
    const revalidate = async () => {
      if (document.visibilityState !== 'visible') return
      if (Date.parse(detail.progress_scope.expires_at) <= Date.now()) {
        window.history.replaceState(null, '', `/app/meal-plan/cooking?week=${week}`)
        removeProgress(storageKey(detail.progress_scope))
        setDetail(undefined); setStageIndex(0); setTimers({}); setError('Термін доступу до цієї сесії завершився. Оберіть актуальний варіант.');
        try { if (active) setChoices(await getCookingChoices(week)) } catch { /* The current no-store response remains unavailable. */ }
        return
      }
      try {
        const next = await getCookingSession(detail.template_version.id, detail.session.id, detail.week, detail.mode)
        if (active) setDetail(next)
      } catch (failure) {
        if (!active) return
        if (failure instanceof ApiError && failure.status === 401) { onExit('Сесія завершилася. Увійдіть знову.'); return }
        window.history.replaceState(null, '', `/app/meal-plan/cooking?week=${week}`)
        removeProgress(storageKey(detail.progress_scope))
        setDetail(undefined); setStageIndex(0); setTimers({});
        setError('Призначення або кухонний вибір змінилися. Оновлено доступні сесії.');
        try { setChoices(await getCookingChoices(week)) } catch { setChoices(undefined) }
      }
    }
    document.addEventListener('visibilitychange', revalidate)
    window.addEventListener('focus', revalidate)
    const expiry = window.setInterval(() => { void revalidate() }, 60000)
    return () => { active = false; window.clearInterval(expiry); document.removeEventListener('visibilitychange', revalidate); window.removeEventListener('focus', revalidate) }
  }, [detail?.assignment.id, detail?.template_version.id, detail?.session.id, detail?.mode, detail?.week, detail?.progress_scope.expires_at, week, onExit])

  function discard() {
    if (detail) removeProgress(storageKey(detail.progress_scope))
    window.history.replaceState(null, '', `/app/meal-plan/cooking?week=${week}`)
    setDetail(undefined); setStageIndex(0); setPlayerStarted(false); setTimers({}); setWakeEnabled(false); setError('')
  }
  function backToChoices() {
    window.history.replaceState(null, '', `/app/meal-plan/cooking?week=${week}`)
    setDetail(undefined); setPlayerStarted(false); setWakeEnabled(false); setError('')
  }
  function timerKey(stage: number, block: number) { return `${stage}:${block}` }
  function startTimer(key: string, seconds: number) {
    setTimers(current => { const old = current[key]; const left = old?.remaining ?? seconds; return { ...current, [key]: { remaining: left, endsAt: Date.now() + left * 1000, running: true } } })
  }
  function pauseTimer(key: string) { setTimers(current => { const timer = current[key]; if (!timer?.running) return current; return { ...current, [key]: { remaining: Math.max(0, Math.ceil(((timer.endsAt ?? Date.now()) - Date.now()) / 1000)), endsAt: null, running: false } } }) }
  function resetTimer(key: string, seconds: number) { setTimers(current => ({ ...current, [key]: { remaining: seconds, endsAt: null, running: false } })) }
  function formatSeconds(seconds: number) { return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}` }
  function renderStage(stage: CookingStage, index: number) {
    return <div className="cook-stage" key={index}><div className="cook-stage-title"><span className="eyebrow">Етап {index + 1} з {detail?.session.stages.length}</span><h2>{stage.title}</h2></div>
      <ol className="cook-actions">{stage.blocks.map((block, blockIndex) => <li key={blockIndex}>
        {block.type === 'hint' && <p className="cook-hint">{block.text}</p>}
        {block.type === 'action' && <p>{block.parts.map((part, partIndex) => typeof part === 'string' ? <span key={partIndex}>{part}</span>
          : <strong className="cook-token" key={partIndex}>{part.value} {part.unit_label}</strong>)}</p>}
        {block.type === 'image' && block.url && <button className="cook-image-button" onClick={() => { const images = stage.blocks.flatMap(item => item.type === 'image' && item.url ? [item.url] : []); setGallery(images); setGalleryIndex(images.indexOf(block.url!)) }} aria-label="Відкрити фото етапу"><img src={block.url} alt={block.alt ?? ''} loading="lazy" referrerPolicy="no-referrer" /></button>}
        {block.type === 'timer' && (() => { const key = timerKey(index, blockIndex); const timer = timers[key]; const left = timer?.running ? Math.max(0, Math.ceil(((timer.endsAt ?? now) - now) / 1000)) : (timer?.remaining ?? block.seconds); return <div className="cook-timer"><strong aria-live="off">{formatSeconds(left)}</strong><span>{block.label}</span>{block.message && <p>{block.message}</p>}{!timer?.running ? <button onClick={() => startTimer(key, block.seconds)}>Почати</button> : <button onClick={() => pauseTimer(key)}>Пауза</button>}<button className="secondary" onClick={() => resetTimer(key, block.seconds)}>Скинути</button></div> })()}
      </li>)}</ol></div>
  }

  if (busy && !choices && !detail) return <section className="cook-shell card" role="status">Завантажуємо доступні кухонні сесії…</section>
  if (error && !detail && !choices) return <section className="cook-shell"><a className="back-link" href={`/app/meal-plan?week=${week}`}>← До раціону</a><p role="alert" className="error">{error}</p></section>
  if (!detail) return <section className="cook-shell">
    <a className="back-link" href={`/app/meal-plan?week=${week}`}>← До раціону</a>
    <header className="cook-heading"><span className="eyebrow">Раціон · тиждень {week}</span><h1>Помічник приготування</h1><p className="muted">Оберіть режим, потім конкретну опубліковану версію та сесію.</p></header>
    {error && <p role="alert" className="error">{error}</p>}
    {!choices?.assignment ? <div className="card cook-empty"><h2>Раціон недоступний</h2><p>Для цього тижня немає активного призначення.</p></div> : choices.modes.map(group => <section className="cook-choice-group" key={group.mode}><h2>{group.mode} {group.mode === 1 ? 'день' : group.mode === 2 ? 'дні' : 'днів'} приготування</h2>{group.templates.length === 0 ? <p className="muted">Опублікованих сесій для цього режиму поки немає.</p> : group.templates.map(template => <article className="card cook-template" key={template.version_id}><div><h3>{template.title}</h3><p>Версія {template.version_number} · {template.sessions.length} сесій</p></div>{template.sessions.map(session => <button className="cook-session-choice" key={session.id} onClick={() => void openSession(template.version_id, session.id, group.mode)}><span>День {session.day} · {session.dishes.map(dish => dish.title).join(', ')}</span><small>{Math.round(session.estimated_seconds / 60)} хв</small></button>)}</article>)}</section>)}
  </section>

  const session = detail.session
  const stage = session.stages[Math.min(stageIndex, session.stages.length - 1)]
  return <section className="cook-shell cook-detail">
    <div className="cook-detail-actions"><button className="back-link cook-back-link" onClick={backToChoices}>← До вибору сесій</button><button className="secondary cook-discard" onClick={discard}>Видалити локальний прогрес</button></div>
    <header className="cook-heading"><span className="eyebrow">{detail.template_version.title} · День {session.day}</span><h1>{session.dishes.map(dish => dish.title).join(' · ')}</h1><p>Орієнтовно {Math.round(session.estimated_seconds / 60)} хв</p><h2 className="cook-summary-title">Що приготуємо</h2><ul className="cook-dishes">{session.dishes.map(dish => <li key={dish.output_id}><strong>{dish.title}</strong><span>Приготовлено: {numberText(dish.cooked_portions)} порц. · призначено: {numberText(dish.allocated_portions)} · залишок: {numberText(dish.left_over_portions)}</span></li>)}</ul></header>
    {!stage ? <div className="card"><h2>Опубліковані етапи недоступні</h2><button onClick={discard}>Повернутися</button></div> : <>
      {activeTimers.length > 0 && <section className="cook-active-timers" aria-label="Активні таймери"><strong>Активні таймери</strong>{activeTimers.map(([key, timer]) => <span key={key}>Етап {Number(key.split(':')[0]) + 1}: {formatSeconds(Math.max(0, Math.ceil(((timer.endsAt ?? now) - now) / 1000)))}</span>)}</section>}
      {!playerStarted ? <>
      <div className="cook-intro-layout"><div>
        {session.introduction && <p className="cook-intro">{session.introduction}</p>}
        <section className="card cook-equipment"><h2>Обладнання</h2><div className="cook-equipment-grid">{session.equipment.map(item => <article key={item.id}>{item.image_url ? <img src={item.image_url} alt="" loading="lazy" referrerPolicy="no-referrer" /> : <div className="cook-photo-placeholder" aria-hidden="true">◌</div>}<span>{item.name}</span></article>)}</div>{session.equipment_notes && <p className="muted">{session.equipment_notes}</p>}</section>
        <section className="card cook-ingredients"><h2>Інгредієнти</h2><ul>{session.ingredients.map(item => <li key={`${item.id}:${item.name}`}><span>{item.name}</span><strong>{numberText(item.quantity_grams)} г</strong></li>)}</ul></section>
      </div><aside className="card cook-outline"><h2>Етапи</h2><ol>{session.stages.map((item, index) => <li key={index}><button aria-current={index === stageIndex ? 'step' : undefined} onClick={() => setStageIndex(index)}>{index + 1}. {item.title}</button></li>)}</ol></aside></div>
      <label className="cook-wake"><input type="checkbox" checked={wakeEnabled} onChange={event => setWakeEnabled(event.target.checked)} /><span>Не вимикати екран</span><small>{wakeState === 'active' ? 'Увімкнено' : wakeState === 'lost' ? 'З’єднання втрачено — повторимо за можливості' : wakeState === 'unsupported' ? 'Цей браузер не підтримує режим' : 'Вимкнено'}</small></label>
      <button className="cook-start" onClick={() => setPlayerStarted(true)}>{stageIndex > 0 || activeTimers.length > 0 ? 'Продовжити' : 'Почати'}</button>
      </> : <>
      <button className="back-link cook-back-link" onClick={() => setPlayerStarted(false)}>← До огляду сесії</button>
      <label className="cook-wake"><input type="checkbox" checked={wakeEnabled} onChange={event => setWakeEnabled(event.target.checked)} /><span>Не вимикати екран</span><small>{wakeState === 'active' ? 'Увімкнено' : wakeState === 'lost' ? 'З’єднання втрачено — повторимо за можливості' : wakeState === 'unsupported' ? 'Цей браузер не підтримує режим' : 'Вимкнено'}</small></label>
      <div className="cook-player">{renderStage(stage, stageIndex)}</div>
      <nav className="cook-player-nav" aria-label="Навігація етапами"><button className="secondary" disabled={stageIndex === 0} onClick={() => setStageIndex(index => Math.max(0, index - 1))}>Назад</button><strong>{stageIndex + 1} / {session.stages.length}</strong><button disabled={stageIndex >= session.stages.length - 1} onClick={() => setStageIndex(index => Math.min(session.stages.length - 1, index + 1))}>{stageIndex === session.stages.length - 1 ? 'Завершено' : 'Далі'}</button></nav>
      </>}
    </>}
    {gallery && <div className="cook-gallery" role="dialog" aria-modal="true" aria-label="Фото етапу" onClick={() => setGallery(undefined)}><button aria-label="Закрити фото" onClick={() => setGallery(undefined)}>×</button><img src={gallery[galleryIndex]} alt="Фото етапу" onClick={event => event.stopPropagation()} /><nav><button disabled={galleryIndex === 0} onClick={event => { event.stopPropagation(); setGalleryIndex(index => index - 1) }}>Попереднє фото</button><span>{galleryIndex + 1} / {gallery.length}</span><button disabled={galleryIndex >= gallery.length - 1} onClick={event => { event.stopPropagation(); setGalleryIndex(index => index + 1) }}>Наступне фото</button></nav></div>}
  </section>
}
