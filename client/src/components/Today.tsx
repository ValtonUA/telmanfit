import { Link } from 'react-router-dom'
import { useEffect, useRef, useState } from 'react'
import { ApiError } from '../api'
import { getMealPlanDays } from '../mealPlanApi'
import type { MealPlanResponse } from '../mealPlanApi'
import { Dish } from './MealPlan'
import './MealPlan.css'
import './Today.css'

function dateTitle(value: string): string {
  return new Intl.DateTimeFormat('uk-UA', {
    day: 'numeric', month: 'long', weekday: 'long', timeZone: 'UTC',
  }).format(new Date(value + 'T12:00:00Z'))
}

export function Today({ onExit }: { onExit: (message: string) => void }) {
  const [menu, setMenu] = useState<MealPlanResponse>()
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [attempt, setAttempt] = useState(0)
  const exit = useRef(onExit)
  useEffect(() => { exit.current = onExit }, [onExit])
  useEffect(() => {
    let active = true
    let generation = 0
    const load = async () => {
      const request = ++generation
      setMenu(undefined); setState('loading')
      try {
        const result = await getMealPlanDays()
        if (active && request === generation) { setMenu(result); setState('ready') }
      } catch (error) {
        if (!active || request !== generation) return
        if (error instanceof ApiError && error.status === 401) exit.current('Сесія завершилася. Увійдіть знову.')
        else setState('error')
      }
    }
    void load()
    const refresh = () => { if (document.visibilityState === 'visible') void load() }
    document.addEventListener('visibilitychange', refresh)
    window.addEventListener('focus', refresh)
    const timer = window.setInterval(refresh, 60000)
    return () => { active = false; generation++; document.removeEventListener('visibilitychange', refresh); window.removeEventListener('focus', refresh); window.clearInterval(timer) }
  }, [attempt])

  const today = menu?.days.find(day => day.date === menu.today)
  return <div className="meal-plan today-page">
    <div className="today-heading">
      <span className="eyebrow">Ваш день</span>
      <h1>Сьогодні</h1>
      {menu?.today && <p className="muted">{dateTitle(menu.today)}</p>}
    </div>
    <div className="today-actions">
      <Link className="button" to="/app/meal-plan">Переглянути раціон</Link>
    </div>
    {state === 'loading' ? <section className="card plan-state" role="status"><h2>Завантажуємо меню…</h2></section>
      : state === 'error' ? <section className="card plan-state"><h2>Меню недоступне</h2><p role="alert">Не вдалося завантажити меню. Перевірте з’єднання.</p><button onClick={() => setAttempt(n => n + 1)}>Спробувати ще раз</button></section>
      : !menu?.assignment ? <section className="card plan-state"><h2>Поки немає призначеного раціону</h2><p>Коли тренер призначить раціон, страви з’являться тут.</p></section>
      : !today ? <section className="card plan-state"><h2>На сьогодні меню не заплановано</h2><p>Можна переглянути інші доступні дні у вкладці «Раціон».</p></section>
      : <section className="today-menu" aria-label="Меню на сьогодні">
        <h2>Меню на сьогодні</h2>
        {today.meals.some(meal => meal.items.length > 0) ? today.meals.filter(meal => meal.items.length > 0).map(meal =>
          <section className="meal-section" key={meal.id}>
            <h3 className="meal-title">{meal.title}</h3>
            <ul className="dish-list">{meal.items.map(item =>
              <Dish key={item.id} item={item} week={menu.week} day={today.day_number} />)}</ul>
          </section>)
          : <div className="card plan-state"><p>У цей день немає доступних страв. Уточніть меню в тренера.</p></div>}
      </section>}
  </div>
}
