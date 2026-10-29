// Перевірені відповіді живуть тільки в пам'яті та лише в межах поточної сесії.
let userId: string | undefined
let generation = 0
const values = new Map<string, unknown>()

export function activatePrivateSession(id: string): number {
  if (userId !== id) {
    values.clear()
    userId = id
    generation++
  }
  return generation
}

export function beginPrivateSession(id: string): number {
  values.clear()
  userId = id
  generation++
  return generation
}

export function clearPrivateSession(): void {
  values.clear()
  userId = undefined
  generation++
}

export function privateSessionGeneration(): number { return generation }

export function privateData<T>(key: string): T | undefined { return values.get(key) as T | undefined }

export function rememberPrivateData<T>(key: string, value: T): void {
  if (userId) values.set(key, value)
}

export function forgetPrivateData(prefix: string): void {
  for (const key of values.keys()) if (key.startsWith(prefix)) values.delete(key)
}
