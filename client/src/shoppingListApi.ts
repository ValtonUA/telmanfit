import { api } from './api'
import type { DayReference, MealPlanResponse } from './mealPlanApi'

// T5.3: точні рядкові кількості, без арифметики в браузері та округлення до упаковок.
export type ShoppingSource = {
  uom_id: string
  unit_label: string
  quantity: string
  quantity_exact: string
  grams_per_unit_exact: string
  conversion_basis: 'stored_ratio' | 'frozen_conversion'
}
export type ShoppingItem = {
  ingredient_id: string
  name: string
  quantity_grams: string
  quantity_grams_exact: string
  source_quantities: ShoppingSource[]
}
export type ShoppingListResponse = Pick<MealPlanResponse, 'assignment' | 'weeks' | 'week'> & {
  days: DayReference[]
  state: 'ready' | 'no_assignment' | 'week_unavailable'
  items: ShoppingItem[]
}

export function getShoppingList(week?: number): Promise<ShoppingListResponse> {
  return api<ShoppingListResponse>('meal-plan/shopping-list' + (week === undefined ? '' : '?week=' + week))
}
