import { useCallback, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'

// У декларативному router navigate змінює identity разом із location.
// Стабільний callback не перезапускає private fetch, таймери та Wake Lock.
export function useClientNavigate() {
  const navigate = useNavigate()
  const latest = useRef(navigate)
  useEffect(() => { latest.current = navigate }, [navigate])
  return useCallback((to: string, replace = false) => {
    void latest.current(to, { replace })
  }, [])
}
