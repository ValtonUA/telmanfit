import { useEffect, useState } from 'react'

type HealthState = 'loading' | 'ok' | 'error'

interface HealthResponse {
  status: string
}

const labels: Record<HealthState, string> = {
  loading: 'Перевірка з’єднання…',
  ok: 'API підключено',
  error: 'API недоступне',
}

/**
 * Calls the public Laravel endpoint GET /api/health and reports the result.
 *
 * The request uses a relative URL: in development it is proxied by Vite to
 * 127.0.0.1:8000, in production the SPA and the API share the same origin.
 */
export default function HealthStatus() {
  const [state, setState] = useState<HealthState>('loading')
  const [status, setStatus] = useState<string | null>(null)

  useEffect(() => {
    const controller = new AbortController()

    const checkHealth = async () => {
      try {
        const response = await fetch('/api/health', {
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        })

        if (!response.ok) {
          throw new Error(`Unexpected response: ${response.status}`)
        }

        const data = (await response.json()) as HealthResponse
        setStatus(data.status)
        setState(data.status === 'ok' ? 'ok' : 'error')
      } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
          return
        }

        setState('error')
      }
    }

    void checkHealth()

    return () => controller.abort()
  }, [])

  return (
    <div className={`health health--${state}`}>
      <span className="health__dot" aria-hidden="true" />
      <span>{labels[state]}</span>
      {status !== null && <code>{status}</code>}
    </div>
  )
}
