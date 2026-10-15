import api from './api'

// Espelha os limites do servidor (o servidor é quem manda: isto só evita um envio inútil).
export const ACCEPTED = {
  'image/jpeg': 25, 'image/png': 25, 'image/webp': 25, 'image/gif': 25,
  'video/mp4': 500, 'video/webm': 500, 'video/quicktime': 500,
}
export const ACCEPT_ATTR = Object.keys(ACCEPTED).join(',')
const CHUNK_SIZE = 5 * 1024 * 1024

export function validateFile(file) {
  const maxMb = ACCEPTED[file.type]
  if (!maxMb) return 'Tipo não permitido. Use JPG, PNG, WebP, GIF, MP4, WebM ou MOV.'
  if (file.size > maxMb * 1024 * 1024) return `Arquivo acima do limite de ${maxMb} MB.`
  if (file.size === 0) return 'Arquivo vazio.'
  return null
}

/**
 * Envia um arquivo em partes de 5 MB: cria a sessão, envia cada parte e finaliza.
 * onProgress recebe 0..1. Com signal (AbortController) o envio pode ser cancelado.
 */
export async function uploadFile(tenantId, file, { onProgress = () => {}, signal } = {}) {
  const base = `/tenants/${tenantId}/uploads`
  const { data: session } = await api.post(base, {
    original_name: file.name, mime_type: file.type, size_bytes: file.size, chunk_size: CHUNK_SIZE,
  }, { signal })
  const id = session.data.id
  const total = session.data.total_chunks

  try {
    for (let i = 0; i < total; i++) {
      const body = new FormData()
      body.append('chunk', file.slice(i * CHUNK_SIZE, Math.min(file.size, (i + 1) * CHUNK_SIZE)), 'chunk.bin')
      await api.post(`${base}/${id}/chunks/${i}`, body, {
        signal,
        onUploadProgress: (e) => onProgress(Math.min(1, (i * CHUNK_SIZE + (e.loaded || 0)) / file.size)),
      })
      onProgress(Math.min(1, ((i + 1) * CHUNK_SIZE) / file.size))
    }
    const { data } = await api.post(`${base}/${id}/complete`, null, { signal })
    onProgress(1)
    return data.data
  } catch (e) {
    // Cancelamento ou falha: libera as partes temporárias no servidor.
    api.delete(`${base}/${id}`).catch(() => {})
    throw e
  }
}
