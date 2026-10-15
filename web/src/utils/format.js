export function formatBytes(bytes) {
  if (bytes == null) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`
  return `${(bytes / 1024 ** 3).toFixed(2)} GB`
}

export function formatDate(iso) {
  return iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : ''
}

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })
export const formatMoney = (cents) => brl.format((cents || 0) / 100)

/** "12,50" -> 1250 (centavos inteiros). Retorna null se inválido. */
export function reaisToCents(text) {
  const n = parseFloat(String(text).replace(/\./g, '').replace(',', '.'))
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) : null
}
export const centsToReais = (cents) => (cents / 100).toFixed(2).replace('.', ',')

export function uuid() {
  return globalThis.crypto?.randomUUID ? crypto.randomUUID() : 'k' + Math.random().toString(36).slice(2) + Date.now().toString(36)
}
