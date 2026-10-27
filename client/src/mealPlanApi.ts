import { api } from './api'

export type DayReference = { day_number: number; date: string }
export type MealItem = { id: string; kind: 'unavailable' } | {
  id: string; kind: 'ingredient' | 'recipe'; title: string; image_url: string | null
  quantity: string; quantity_label: string; recipe_version_id: string | null
}
export type MenuDay = DayReference & { meals: { id: string; title: string; items: MealItem[] }[] }
export type MealPlanResponse = {
  assignment: null | { id: string; start_date: string; end_date: string | null
    meal_plan_version: { id: string; title: string } }
  today?: string; plan_start_date?: string
  weeks: { number: number; days: DayReference[] }[]; week: number | null; days: MenuDay[]
}

// Єдине типізоване читання календаря T5.2: жодної клієнтської арифметики порцій.
export function getMealPlanDays(week?: number): Promise<MealPlanResponse> {
  return api<MealPlanResponse>('meal-plan/days' + (week ? '?week=' + week : ''))
}
