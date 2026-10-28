import { api } from './api'

export type CookingDish = { output_id: string; recipe_version_id: string; title: string; cooked_portions: string; allocated_portions: string; left_over_portions: string; allocations: { day: number; date: string; portions: string }[] }
export type CookingSessionSummary = { id: string; day: number; date: string; position: number; estimated_seconds: number; dishes: CookingDish[] }
export type CookingChoices = { assignment: null | { id: string; start_date: string; end_date: string | null; meal_plan_version_id: string; meal_plan_title: string }; week: number; week_start?: string; modes: { mode: 1 | 2 | 7; available: boolean; templates: { version_id: string; template_id: string; title: string; version_number: number; mode: number; sessions: CookingSessionSummary[] }[] }[] }
export type CookingStage = { id: string; title: string; blocks: ({ id: string; type: 'hint'; text: string } | { id: string; type: 'image'; url: string | null; alt: string } | { id: string; type: 'action'; parts: (string | { type: 'token'; value: string; metric: string; unit_label: string; sources: unknown[] })[] } | { id: string; type: 'timer'; seconds: number; label: string; message: string })[] }
export type CookingDetail = { assignment: { id: string }; week: number; mode: number; date: string; template_version: { id: string; template_id: string; title: string; version_number: number }; session: { id: string; day: number; position: number; introduction: string | null; equipment_notes: string | null; estimated_seconds: number; dishes: CookingDish[]; equipment: { id: string; name: string; image_url: string | null }[]; ingredients: { id: string; name: string; quantity_grams: string; image_url: string | null }[]; stages: CookingStage[] }; progress_scope: { client_id: string; assignment_id: string; week: number; date: string; cooking_version_id: string; session_id: string; expires_at: string } }

export function getCookingChoices(week: number) { return api<CookingChoices>(`meal-plan/cooking?week=${week}`) }
export function getCookingSession(version: string, session: string, week: number, mode: number) {
  return api<CookingDetail>(`meal-plan/cooking/${version}/sessions/${session}?week=${week}&mode=${mode}`)
}
