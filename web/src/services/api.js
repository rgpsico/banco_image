import axios from 'axios'

const TOKEN_KEY = 'mediahub_token'
const TENANT_KEY = 'mediahub_tenant'

// Token em localStorage (simples para o MVP). Para endurecer: cookie HttpOnly via Sanctum SPA (ver docs/security.md).
export const storage = {
  get token() { return localStorage.getItem(TOKEN_KEY) },
  set token(v) { v ? localStorage.setItem(TOKEN_KEY, v) : localStorage.removeItem(TOKEN_KEY) },
  get tenant() { return localStorage.getItem(TENANT_KEY) },
  set tenant(v) { v ? localStorage.setItem(TENANT_KEY, v) : localStorage.removeItem(TENANT_KEY) },
}

const api = axios.create({ baseURL: '/api/v1', headers: { Accept: 'application/json' } })

api.interceptors.request.use((config) => {
  if (storage.token) config.headers.Authorization = `Bearer ${storage.token}`
  // O X-Tenant apenas SELECIONA o projeto: o servidor confere se o usuário é membro.
  if (storage.tenant) config.headers['X-Tenant'] = storage.tenant
  return config
})

let onUnauthorized = () => {}
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn }

api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error.response?.status === 401 && storage.token) onUnauthorized()
    return Promise.reject(error)
  },
)

/** Converte erros do Axios em { message, fields } para exibir nos formulários. */
export function parseError(error) {
  const data = error.response?.data
  if (!error.response) return { message: 'Não foi possível conectar ao servidor.', fields: {} }
  const fields = {}
  for (const [k, v] of Object.entries(data?.errors || {})) fields[k] = Array.isArray(v) ? v[0] : v
  const first = Object.values(fields)[0]
  return { message: data?.message && error.response.status !== 422 ? data.message : (first || data?.message || 'Erro inesperado.'), fields }
}

export default api
