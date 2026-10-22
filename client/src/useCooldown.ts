import { useEffect, useState } from 'react'

export function useCooldown() {
  const [until, setUntil] = useState(0)
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  return { remaining: Math.max(0, Math.ceil((until - now) / 1000)), start: (seconds = 60) => { setNow(Date.now()); setUntil(Date.now() + seconds * 1000) } }
}

