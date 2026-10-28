import { Link } from 'react-router-dom'
import { useEffect, useRef, useState } from 'react'
import { useClientNavigate } from '../useClientNavigate'
import { ApiError } from '../api'
import { getShoppingList } from '../shoppingListApi'
import type { ShoppingItem, ShoppingListResponse } from '../shoppingListApi'
import './ShoppingList.css'

const shoppingPath = '/app/meal-plan/shopping-list'

function requestedWeek(): number | undefined {
  const raw = new URLSearchParams(window.location.search).get('week')
  if (raw === null) return undefined
  return /^[1-9][0-9]*$/.test(raw) && Number.isSafeInteger(Number(raw)) && Number(raw) <= 2147483647
    ? Number(raw) : Number.NaN
}

function shortDate(value: string): string {
  return new Intl.DateTimeFormat('uk-UA', { day: 'numeric', month: 'short', timeZone: 'UTC' })
    .format(new Date(value + 'T12:00:00Z'))
}

function displayDecimal(value: string): string {
  // Не перераховуємо й не округлюємо: лише прибираємо нулі в кінці серверного decimal.
  return value.replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1')
}

function ShoppingProduct({ item }: { item: ShoppingItem }) {
  return <li className="shopping-product">
    <div className="shopping-product-primary">
      <h3>{item.name}</h3>
      <strong>{displayDecimal(item.quantity_grams)} г</strong>
    </div>
    {item.quantity_grams_exact.includes('/') &&
      <p className="shopping-exact">Точна кількість: {item.quantity_grams_exact} г</p>}
    {item.source_quantities.length > 0 && <details className="shopping-sources">
      <summary>Вихідні одиниці з раціону</summary>
      <ul>{item.source_quantities.map((source, index) =>
        <li key={source.uom_id + '-' + source.conversion_basis + '-' + index}>
          {source.quantity_exact} {source.unit_label}
          {source.conversion_basis === 'frozen_conversion' ? ' · за збереженою конверсією' : ' · за історичним співвідношенням'}
        </li>)}</ul>
    </details>}
  </li>
}

export function ShoppingList({ onExit }: { onExit: (message: string) => void }) {
  const navigate = useClientNavigate()
  const [week, setWeek] = useState<number | undefined>(requestedWeek)
  const [result, setResult] = useState<ShoppingListResponse>()
  const [loading, setLoading] = useState(true)
  const [issue, setIssue] = useState<'invalid' | 'unavailable' | 'network'>()
  const [retry, setRetry] = useState(0)
  const exit = useRef(onExit)
  useEffect(() => { exit.current = onExit }, [onExit])

  function chooseWeek(next: number | undefined) {
    const query = next === undefined ? '' : '?week=' + next
    navigate(shoppingPath + query, true)
    setWeek(next)
    setRetry(n => n + 1)
  }

  useEffect(() => {
    let active = true
    let generation = 0
    const load = async () => {
      const request = ++generation
      setResult(undefined); setIssue(undefined); setLoading(true)
      if (week !== undefined && (!Number.isInteger(week) || week < 1 || week > 2147483647)) {
        if (active && request === generation) { setIssue('invalid'); setLoading(false) }
        return
      }
      try {
        const data = await getShoppingList(week)
        if (active && request === generation) { setResult(data); setLoading(false) }
      } catch (failure) {
        if (!active || request !== generation) return
        setLoading(false)
        if (failure instanceof ApiError && failure.status === 401) {
          exit.current('Сесія завершилася. Увійдіть знову.')
        } else {
          setIssue(failure instanceof ApiError && failure.status === 409 ? 'unavailable' : 'network')
        }
      }
    }
    void load()
    const refresh = () => { if (document.visibilityState === 'visible') void load() }
    document.addEventListener('visibilitychange', refresh)
    window.addEventListener('focus', refresh)
    const timer = window.setInterval(refresh, 60000)
    return () => {
      active = false; generation++
      document.removeEventListener('visibilitychange', refresh)
      window.removeEventListener('focus', refresh)
      window.clearInterval(timer)
    }
  }, [week, retry])

  const back = '/app/meal-plan' + (result?.week ? '?week=' + result.week : week ? '?week=' + week : '')
  return <section className="shopping-list">
    <Link className="shopping-back" to={back}>← Повернутися до раціону</Link>
    <div className="shopping-heading">
      <div><span className="eyebrow">Раціон · покупки</span><h1>Список покупок</h1></div>
      <button className="secondary" disabled={loading} onClick={() => setRetry(n => n + 1)}>Оновити</button>
    </div>
    <p className="shopping-note">Кількості розраховані за призначеним раціоном. Це потреба в продуктах, а не кількість магазинних упаковок.</p>
    {loading ? <div className="card shopping-state" role="status"><h2>Розраховуємо список…</h2></div>
      : issue ? <div className="card shopping-state">
        <h2>{issue === 'invalid' ? 'Некоректний номер тижня'
          : issue === 'unavailable' ? 'Повний список покупок недоступний' : 'Не вдалося завантажити список'}</h2>
        <p role="alert">{issue === 'unavailable'
          ? 'Для одного з продуктів бракує перевірених даних. Частковий список не показуємо. Зверніться до тренера.'
          : issue === 'invalid' ? 'Виберіть тиждень із доступного раціону.'
            : 'Перевірте з’єднання й спробуйте ще раз.'}</p>
        {issue === 'invalid' ? <button onClick={() => chooseWeek(undefined)}>Поточний тиждень</button>
          : <button onClick={() => setRetry(n => n + 1)}>Спробувати ще раз</button>}
      </div>
      : !result?.assignment || result.state === 'no_assignment' ?
        <div className="card shopping-state"><h2>Поки немає призначеного раціону</h2><p>Список покупок з’явиться після призначення раціону.</p></div>
      : <>
        <div className="card shopping-period">
          <h2>{result.assignment.meal_plan_version.title}</h2>
          {result.weeks.length > 0 && <label htmlFor="shopping-week">Тиждень раціону
            <select id="shopping-week" value={result.week ?? ''} onChange={event => chooseWeek(Number(event.target.value))}>
              {result.week !== null && !result.weeks.some(w => w.number === result.week)
                && <option value={result.week}>Недоступний тиждень</option>}
              {result.weeks.map(w => <option key={w.number} value={w.number}>
                Тиждень {w.number} · {shortDate(w.days[0].date)} — {shortDate(w.days[w.days.length - 1].date)}
              </option>)}
            </select>
          </label>}
          {result.days.length > 0 && <p className="shopping-dates">Доступні дні: {shortDate(result.days[0].date)} — {shortDate(result.days[result.days.length - 1].date)}</p>}
        </div>
        {result.state === 'week_unavailable'
          ? <div className="card shopping-state"><h2>Цей тиждень недоступний</h2><p>У призначенні немає доступних днів для цього тижня. Виберіть інший тиждень.</p></div>
          : result.items.length === 0
            ? <div className="card shopping-state"><h2>На цей тиждень немає продуктів</h2><p>Тут поки немає позицій для покупок.</p></div>
            : <section aria-label="Продукти для покупки" className="shopping-results">
              <h2>Необхідні продукти</h2>
              <ul className="shopping-products">{result.items.map(item =>
                <ShoppingProduct key={item.ingredient_id} item={item} />)}</ul>
              <p className="shopping-footnote">Грами — точна харчова потреба; дробові банки, штуки чи інші вихідні одиниці не є рекомендацією щодо фасування. Округлення до упаковок ще не погоджене.</p>
            </section>}
      </>}
  </section>
}
