const shortFormatter = new Intl.DateTimeFormat('ru-RU', {
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Europe/Moscow',
})

const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
  day: '2-digit',
  month: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Europe/Moscow',
})

export function formatTime(value?: string | null): string {
  if (!value) return '—'
  return shortFormatter.format(new Date(value))
}

export function formatDateTime(value?: string | null): string {
  if (!value) return '—'
  return dateFormatter.format(new Date(value))
}

export function minutesUntil(value?: string | null): number | null {
  if (!value) return null
  const delta = new Date(value).getTime() - Date.now()
  return Math.max(0, Math.ceil(delta / 60000))
}
