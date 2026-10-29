import { Link } from 'react-router-dom'
import { useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../api'
import { forgetPrivateData, privateData, rememberPrivateData } from '../privateData'
import './Library.css'

export type LibraryCard = {
  id: string
  type: 'article' | 'video' | 'playlist' | 'instruction'
  title: string
  summary: string | null
  cover_image_url: string | null
  published_at: string
}
export type LibraryDetail = LibraryCard & { content_html: string | null; external_url: string | null }

const labels: Record<LibraryCard['type'], string> = {
  article: 'Стаття', video: 'Відео', playlist: 'Плейліст', instruction: 'Інструкція',
}

function Cover({ url, title }: { url: string | null; title: string }) {
  const [broken, setBroken] = useState(false)
  return url && !broken
    ? <img className="library-cover" src={url} alt={title} loading="lazy" referrerPolicy="no-referrer" onError={() => setBroken(true)} />
    : <div className="library-cover library-cover-empty" aria-hidden="true">Матеріал</div>
}

function usePrivateResource<T>(path: string, onExit: (message: string) => void) {
  const key = `library:${path}`
  const [data, setData] = useState<T | undefined>(() => privateData(key))
  const [loading, setLoading] = useState(() => !privateData(key))
  const [error, setError] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const exitRef = useRef(onExit)
  useEffect(() => { exitRef.current = onExit }, [onExit])
  useEffect(() => {
    let active = true
    let generation = 0
    const load = async () => {
      const current = ++generation
      if (!privateData(key)) setLoading(true)
      setError(false)
      try {
        const result = await api<T>(path)
        if (active && current === generation) { rememberPrivateData(key, result); setData(result); setLoading(false) }
      } catch (reason) {
        if (!active || current !== generation) return
        setLoading(false)
        if (reason instanceof ApiError && [403, 404].includes(reason.status)) { forgetPrivateData('library:'); setData(undefined) }
        if (reason instanceof ApiError && reason.status === 401) exitRef.current('Сесія завершилася. Увійдіть знову.')
        else setError(true)
      }
    }
    void load()
    const refresh = () => { if (document.visibilityState === 'visible') void load() }
    document.addEventListener('visibilitychange', refresh)
    window.addEventListener('focus', refresh)
    const timer = window.setInterval(refresh, 60000)
    return () => { active = false; generation++; document.removeEventListener('visibilitychange', refresh)
      window.removeEventListener('focus', refresh); window.clearInterval(timer) }
  }, [path, attempt, key])
  return { data, loading, error, retry: () => setAttempt(n => n + 1) }
}

export function Library({ onExit }: { onExit: (message: string) => void }) {
  const { data, loading, error, retry } = usePrivateResource<{ items: LibraryCard[] }>('library', onExit)
  return <section className="library">
    <span className="eyebrow">Ваш особистий простір</span><h1>Корисне</h1>
    {loading ? <div className="card" role="status">Завантажуємо матеріали…</div>
      : error && !data ? <div className="card"><h2>Не вдалося завантажити матеріали</h2><p role="alert">Перевірте з’єднання й спробуйте ще раз.</p><button onClick={retry}>Спробувати ще раз</button></div>
      : !data?.items.length ? <div className="card"><h2>Матеріали поки недоступні</h2><p>Тренер ще не опублікував матеріали.</p></div>
      : <div className="library-grid">{data.items.map(item => <Link key={item.id} className="library-tile" to={'/app/resources/' + item.id}>
          <Cover url={item.cover_image_url} title={item.title} />
          <div className="library-tile-copy"><span className="library-type">{labels[item.type] ?? 'Матеріал'}</span>
            <h2>{item.title}</h2>{item.summary && <p>{item.summary}</p>}</div>
        </Link>)}</div>}
  </section>
}

export function LibraryArticle({ id, onExit }: { id: string; onExit: (message: string) => void }) {
  const { data, loading, error, retry } = usePrivateResource<{ item: LibraryDetail }>('library/' + encodeURIComponent(id), onExit)
  const item = data?.item
  return <article className="library library-article">
    <p><Link to="/app/resources">До бібліотеки</Link></p>
    {loading ? <div className="card" role="status">Завантажуємо матеріал…</div>
      : !item ? <div className="card"><h1>Матеріал недоступний</h1><p role="alert">{error ? 'Його могли зняти з публікації або виникла помилка.' : 'Матеріал недоступний.'}</p><button onClick={retry}>Спробувати ще раз</button></div>
      : <>
        <span className="library-type">{labels[item.type]}</span><h1>{item.title}</h1>
        {item.summary && <p className="muted">{item.summary}</p>}
        {item.cover_image_url && <Cover url={item.cover_image_url} title={item.title} />}
        {item.content_html && <div className="card library-prose" dangerouslySetInnerHTML={{ __html: item.content_html }} />}
        {item.external_url && <p><a className="button" href={item.external_url} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer">Відкрити матеріал</a></p>}
      </>}
  </article>
}
