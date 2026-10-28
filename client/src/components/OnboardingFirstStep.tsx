import { Link } from 'react-router-dom'
import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../api'

type Workplace = 'home' | 'hybrid' | 'office' | ''
type StoredDraft = {
  completed: boolean
  answers?: {
    workplace?: Workplace
    meals_per_day?: number
    top_reasons?: string
  }
}

export function OnboardingFirstStep() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [workplace, setWorkplace] = useState<Workplace>('')
  const [mealsPerDay, setMealsPerDay] = useState<number | ''>('')
  const [topReasons, setTopReasons] = useState('')
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [completed, setCompleted] = useState(false)

  useEffect(() => {
    let active = true
    api<StoredDraft>('onboarding')
      .then(({ answers, completed: archived }) => {
        if (!active) return
        setCompleted(archived)
        const location = answers?.workplace
        setWorkplace(location === 'home' || location === 'hybrid' || location === 'office' ? location : '')
        const count = answers?.meals_per_day
        setMealsPerDay(typeof count === 'number' && count >= 1 && count <= 5 ? count : '')
        setTopReasons(answers?.top_reasons ?? '')
      })
      .catch((failure: unknown) => { if (active) setError(failure instanceof Error ? failure.message : 'Не вдалося завантажити анкету.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setSaved(false)
    setError('')
    try {
      await api('onboarding', {
        workplace,
        meals_per_day: mealsPerDay,
        top_reasons: topReasons.trim(),
      })
      setSaved(true)
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Не вдалося зберегти відповіді.')
    } finally {
      setSaving(false)
    }
  }

  return <section className="card onboarding-card">
    <span className="eyebrow">Перший робочий розділ</span>
    <h1>Початкова анкета</h1>
    <p className="muted">Перші три запитання з вашої анкети «Початок супроводу». Чернетку можна змінювати до завершення анкети.</p>
    {loading ? <p role="status">Завантажуємо відповіді…</p> : <form onSubmit={save}>
      <label htmlFor="onboarding-reasons">ТОП-3 причини, що заважають вам схуднути</label>
      <textarea id="onboarding-reasons" disabled={completed} required minLength={1} maxLength={1000} rows={4}
        value={topReasons} onChange={event => { setTopReasons(event.target.value); setSaved(false) }}
        placeholder="Наприклад: нерегулярне харчування, мало часу на готування, вечірній голод" />
      <label htmlFor="onboarding-workplace">Ви працюєте з дому чи в офісі?</label>
      <select id="onboarding-workplace" disabled={completed} required value={workplace}
        onChange={event => { setWorkplace(event.target.value as Workplace); setSaved(false) }}>
        <option value="">Оберіть варіант</option>
        <option value="home">Повністю з дому</option>
        <option value="hybrid">Частково з дому, частково в офісі</option>
        <option value="office">Повністю в офісі</option>
      </select>
      <label htmlFor="onboarding-meals">Зручна кількість прийомів їжі на день</label>
      <select id="onboarding-meals" disabled={completed} required value={mealsPerDay}
        onChange={event => { setMealsPerDay(event.target.value ? Number(event.target.value) : ''); setSaved(false) }}>
        <option value="">Оберіть кількість</option>
        {[1, 2, 3, 4, 5].map(count => <option value={count} key={count}>{count}</option>)}
      </select>
      {error && <p role="alert" className="error">{error}</p>}
      {saved && <p role="status" className="notice">Відповіді збережено. Можна перезавантажити сторінку — вони залишаться тут.</p>}
      {completed ? <p role="status">Анкету завершено. Початкові відповіді збережено для перегляду.</p> : <button disabled={saving}>{saving ? 'Зберігаємо…' : 'Зберегти відповіді'}</button>}
      <p className="fine-print">Це перша частина анкети. Повна анкета буде розширена; поточне збереження не активує супровід.</p>
    </form>}
    {!loading && error && <p role="alert" className="error">{error}</p>}
    <Link className="text-link" to="/app">← Повернутися до кабінету</Link>
  </section>
}
