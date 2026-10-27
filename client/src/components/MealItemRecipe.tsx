import { useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../api'
import './MealPlan.css'
import './MealItemRecipe.css'

type Recipe = {
  meal_item_id: string; week: number; day_number: number; title: string; image_url: string | null
  assigned_servings_label: string; format: string | null; preparation_servings: string
  ingredients: { id: string; name: string; quantity: string; unit_label: string; component: string | null }[]
  equipment: { id: string; name: string; min_value: string | null; unit_label: string; tip: string }[]
  directions: { number: number; text: string; image_url: string | null }[]
}
function amount(value: string): string { return value.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '').replace('.', ',') }
function servings(value: string): string {
  const n = Number(value)
  const unit = !Number.isInteger(n) ? 'порції' : n % 10 === 1 && n % 100 !== 11 ? 'порція'
    : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'порції' : 'порцій'
  return `${amount(value)} ${unit}`
}
function Photo({ url, label }: { url: string | null; label: string }) {
  const [failed, setFailed] = useState(false)
  return url && !failed ? <img src={url} alt={label} loading="lazy" referrerPolicy="no-referrer" onError={() => setFailed(true)} />
    : <div className="photo-placeholder recipe-photo-fallback"><span aria-hidden="true">◌</span><span>Фото відсутнє</span></div>
}

export function MealItemRecipe({ itemId, onExit }: { itemId: string; onExit: (message: string) => void }) {
  const [data, setData] = useState<Recipe>()
  const [state, setState] = useState<'loading' | 'ready' | 'unavailable' | 'error'>('loading')
  const [attempt, setAttempt] = useState(0)
  const exit = useRef(onExit)
  useEffect(() => { exit.current = onExit }, [onExit])
  useEffect(() => {
    let generation = 0
    let active = true
    const load = async () => {
      const request = ++generation
      setData(undefined); setState('loading')
      try {
        const result = await api<Recipe>(`meal-plan/items/${encodeURIComponent(itemId)}/recipe`)
        if (active && request === generation) { setData(result); setState('ready') }
      } catch (error) {
        if (!active || request !== generation) return
        if (error instanceof ApiError && error.status === 401) exit.current('Сесія завершилася. Увійдіть знову.')
        else setState(error instanceof ApiError && error.status === 404 ? 'unavailable' : 'error')
      }
    }
    void load()
    const refresh = () => { if (document.visibilityState === 'visible') void load() }
    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', refresh)
    const timer = window.setInterval(refresh, 60000)
    return () => { active = false; generation++; window.clearInterval(timer); window.removeEventListener('focus', refresh); document.removeEventListener('visibilitychange', refresh) }
  }, [itemId, attempt])
  const params = new URLSearchParams(window.location.search)
  const week = Number(params.get('week')) || data?.week
  const day = Number(params.get('day')) || data?.day_number
  const back = `/app/meal-plan${week && day ? `?week=${week}&day=${day}` : ''}`
  return <article className="meal-plan recipe-page">
    <a className="back-link" href={back}>← Повернутися до раціону</a>
    {state === 'loading' ? <section className="card plan-state" role="status"><h1>Завантажуємо рецепт…</h1></section>
      : state === 'unavailable' ? <section className="card plan-state"><h1>Рецепт недоступний</h1><p>Призначення могло змінитися або рецепт потребує перевірки тренером. Поверніться до актуального раціону.</p></section>
      : state === 'error' ? <section className="card plan-state"><h1>Не вдалося завантажити рецепт</h1><p role="alert">Перевірте з’єднання та спробуйте ще раз.</p><button onClick={() => setAttempt(n => n + 1)}>Спробувати ще раз</button></section>
      : data && <>
        <header className="recipe-intro card"><div className="recipe-cover"><Photo key={data.image_url} url={data.image_url} label={data.title} /></div><div>
          <span className="eyebrow">Рецепт від тренера</span><h1>{data.title}</h1>
          <p className="recipe-assigned">Призначено: {data.assigned_servings_label}</p>
          {data.format && <p>Формат приготування: <strong>{data.format}</strong></p>}
          <p>Рецепт приготування: <strong>{servings(data.preparation_servings)}</strong></p>
          <p className="muted">Нижче наведено повний склад для приготування. Призначена вам кількість порцій вказана окремо.</p>
        </div></header>
        <div className="recipe-columns"><section className="card recipe-ingredients"><h2>Інгредієнти</h2><ul>{data.ingredients.map(row => <li key={row.id}>
          <div>{row.component && <span className="recipe-component">{row.component}</span>}<span>{row.name}</span></div><strong>{amount(row.quantity)} {row.unit_label}</strong>
        </li>)}</ul></section>
        <section className="card"><h2>Кухонне обладнання</h2>{data.equipment.length === 0 ? <p className="muted">Додаткове обладнання не вказано.</p>
          : <ul className="recipe-equipment">{data.equipment.map(row => <li key={row.id}><strong>{row.name}</strong><p>{row.min_value === null ? 'Без числової вимоги' : `${amount(row.min_value)} ${row.unit_label}`}</p>{row.tip && <p className="muted">{row.tip}</p>}</li>)}</ul>}</section></div>
        <section className="card recipe-directions"><span className="eyebrow">Готуємо крок за кроком</span><h2>Приготування</h2>
          {data.directions.length === 0 ? <p className="muted">Тренер ще не додав інструкцію. Зверніться до нього перед приготуванням.</p>
            : <ol>{data.directions.map(step => <li key={step.number}><h3>Крок {step.number}</h3><p>{step.text}</p>{step.image_url && <div className="recipe-step-photo"><Photo key={step.image_url} url={step.image_url} label={`Фото кроку ${step.number}`} /></div>}</li>)}</ol>}
        </section>
        <a className="button" href={back}>Повернутися до раціону</a>
      </>}
  </article>
}
