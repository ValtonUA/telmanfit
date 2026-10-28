import { Link, useSearchParams } from 'react-router-dom'
import { useCallback, useEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { getMealPlanDays } from '../mealPlanApi'
import type { MealItem, MealPlanResponse } from '../mealPlanApi'
import './MealPlan.css'
import './MealItemRecipe.css'

function dateLabel(date: string, short = false): string {
  return new Intl.DateTimeFormat('uk-UA', short ? { weekday: 'short', day: 'numeric', month: 'short' }
    : { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${date}T12:00:00`))
}

export function Dish({ item, week, day }: { item: MealItem; week: number | null; day: number }) {
  const [failed, setFailed] = useState(false)
  if (item.kind === 'unavailable') return <li className="dish unavailable">Позиція меню тимчасово недоступна. Зверніться до тренера.</li>
  const content = <>
    {item.image_url && !failed
      ? <img className="dish-image" src={item.image_url} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setFailed(true)} />
      : <div className="dish-image photo-placeholder"><span aria-hidden="true">◌</span><span>Фото відсутнє</span></div>}
    <div className="dish-copy"><h3>{item.title}</h3><p className="dish-quantity">{item.quantity_label}</p>
      {item.kind === 'recipe' && <p className="recipe-later">Відкрити рецепт →</p>}
    </div>
  </>
  return <li className="dish">{item.kind === 'recipe' ? <Link className="dish-link" to={`/app/meal-plan/items/${item.id}/recipe?week=${week}&day=${day}`}>{content}</Link> : content}</li>
}

export function MealPlan({ onExit }: { onExit: (message: string) => void }) {
  const [data, setData] = useState<MealPlanResponse>()
  const [params, setParams] = useSearchParams()
  const requestedWeek = Number(params.get('week')) || undefined
  const requestedDay = Number(params.get('day')) || 0
  const selectedDay = data?.days.find(d => d.day_number === requestedDay)?.day_number
    ?? (data?.days.find(d => d.date === data.today) ?? data?.days[0])?.day_number
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [loadedWeek, setLoadedWeek] = useState<number>()
  const [changed, setChanged] = useState(false)
  const generation = useRef(0)
  const current = useRef<MealPlanResponse | undefined>(undefined)
  const exit = useRef(onExit)
  useEffect(() => { exit.current = onExit }, [onExit])
  function invalidateRequests() { generation.current++ }

  const load = useCallback(async (week?: number) => {
    const request = ++generation.current
    setLoading(true); setError('')
    try {
      let result = await getMealPlanDays(week)
      if (request !== generation.current) return
      const previous = current.current
      const replaced = previous && (previous.assignment?.id !== result.assignment?.id
        || previous.assignment?.meal_plan_version.id !== result.assignment?.meal_plan_version.id)
      if (replaced) {
        setData(undefined); setChanged(true)
        if (week) result = await getMealPlanDays()
      }
      if (request !== generation.current) return
      current.current = result; setData(result); setLoadedWeek(requestedWeek)

    } catch (failure) {
      if (request !== generation.current) return
      setData(undefined)
      if (failure instanceof ApiError && failure.status === 401) exit.current('Сесія завершилася. Увійдіть знову.')
      else setError('Не вдалося завантажити раціон. Перевірте з’єднання та спробуйте ще раз.')
    } finally { if (request === generation.current) setLoading(false) }
  }, [requestedWeek])

  useEffect(() => {
    const initial = window.setTimeout(() => { void load(requestedWeek) }, 0)
    const refresh = () => { if (document.visibilityState === 'visible') void load(current.current?.week ?? undefined) }
    document.addEventListener('visibilitychange', refresh)
    window.addEventListener('focus', refresh)
    const timer = window.setInterval(refresh, 60000)
    return () => {
      window.clearTimeout(initial); window.clearInterval(timer)
      // Інвалідуємо незавершені HTTP-відповіді при unmount/StrictMode cleanup.
      invalidateRequests()
      document.removeEventListener('visibilitychange', refresh); window.removeEventListener('focus', refresh)
    }
  }, [requestedWeek, load])
  const pending = loading || Boolean(data && requestedWeek !== loadedWeek)
  const day = data?.days.find(d => d.day_number === selectedDay)
  return <div className="meal-plan">
    <Link className="back-link" to="/app">← Особистий кабінет</Link>
    <div className="plan-heading"><div><span className="eyebrow">Турбота щодня</span><h1>Раціон</h1></div>
      <button className="secondary" disabled={pending} onClick={() => void load(data?.week ?? undefined)}>Оновити</button></div>
    {changed && <p className="notice" role="status">Призначення змінилося. Показуємо актуальний раціон.</p>}
    {pending ? <section className="card plan-state" role="status"><span className="eyebrow">Ваше меню</span><h2>Завантажуємо раціон…</h2><p>Ще мить — і все буде перед вами.</p></section>
      : error ? <section className="card plan-state"><h2>Раціон поки недоступний</h2><p role="alert">{error}</p><button onClick={() => void load(current.current?.week ?? undefined)}>Спробувати ще раз</button></section>
      : !data?.assignment ? <section className="card plan-state"><span className="eyebrow">У вашому темпі</span><h2>Поки немає призначеного раціону</h2><p>Коли тренер призначить раціон, ваше меню з’явиться тут.</p></section>
      : <>
        <section className="plan-calendar card" aria-label="Календар раціону">
          <span className="eyebrow">Ваш призначений план</span><h2 className="plan-title">{data.assignment.meal_plan_version.title}</h2>
          {data.weeks.length > 0 && <>
            <label className="week-picker">Тиждень<select aria-label="Тиждень раціону" value={data.week ?? ''} onChange={event => setParams({ week: event.target.value })}>
              {!data.weeks.some(w => w.number === data.week) && <option value={data.week ?? ''}>Недоступний тиждень</option>}
              {data.weeks.map(week => <option key={week.number} value={week.number}>Тиждень {week.number} · {dateLabel(week.days[0].date, true)} — {dateLabel(week.days[week.days.length - 1].date, true)}</option>)}
            </select></label>
            <nav className="plan-days" aria-label="Дні раціону">{data.days.map(d => <button key={d.day_number} aria-pressed={d.day_number === selectedDay} onClick={() => setParams({ week: String(data.week), day: String(d.day_number) })}>
              <span>{dateLabel(d.date, true)}</span><strong>День {d.day_number}</strong>{d.date === data.today && <small>Сьогодні</small>}
            </button>)}</nav>
          </>}
          <p className="plan-shopping-link"><Link className="button secondary" to={'/app/meal-plan/shopping-list' + (data.week ? '?week=' + data.week : '')}>Список покупок на тиждень</Link></p>
          <p className="plan-shopping-link"><Link className="button" to={`/app/meal-plan/cooking?week=${data.week}`}>Помічник приготування</Link></p>
        </section>
        <section className="day-menu" aria-live="polite" aria-label="Меню вибраного дня">
          {day ? <><div className="day-heading"><span className="eyebrow">День {day.day_number}</span><h2>{dateLabel(day.date)}</h2></div>
            {day.meals.length && day.meals.some(meal => meal.items.length) ? day.meals.map(meal => <section className="meal-section" key={meal.id}>
              <h3 className="meal-title">{meal.title}</h3><ul className="dish-list">{meal.items.map(item => <Dish key={`${item.id}-${item.kind === 'unavailable' ? '' : item.image_url}`} item={item} week={data.week} day={day.day_number} />)}</ul>
              {!meal.items.length && <p className="muted">Для цього прийому меню недоступне.</p>}
            </section>) : <div className="card plan-state"><h3>Для цього дня меню недоступне</h3><p>Зверніться до тренера або виберіть інший день.</p></div>}
          </> : <div className="card plan-state"><h2>Немає доступного дня</h2><p>Дні раціону закінчилися або не входять у період призначення. Уточніть наступний раціон у тренера.</p></div>}
        </section>
      </>}
  </div>
}
