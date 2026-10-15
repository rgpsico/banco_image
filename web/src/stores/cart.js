import { defineStore } from 'pinia'
import { uuid } from '../utils/format'

const KEY = 'mediahub_cart'
const load = () => { try { return JSON.parse(localStorage.getItem(KEY)) || { items: [], checkoutKey: null } } catch { return { items: [], checkoutKey: null } } }

export const useCartStore = defineStore('cart', {
  state: () => load(),
  getters: {
    count: (s) => s.items.length,
    total: (s) => s.items.reduce((sum, i) => sum + i.price_cents, 0),
    has: (s) => (listingId, license) => s.items.some((i) => i.listingId === listingId && i.license === license),
  },
  actions: {
    add(item) {
      if (!this.has(item.listingId, item.license)) this.items.push(item)
      this.touch()
    },
    remove(index) { this.items.splice(index, 1); this.touch() },
    clear() { this.items = []; this.checkoutKey = null; this.persist() },
    // chave de idempotência do checkout: repetir o clique não cria pedidos duplicados; mudar o carrinho gera outra.
    key() { if (!this.checkoutKey) { this.checkoutKey = uuid(); this.persist() } return this.checkoutKey },
    touch() { this.checkoutKey = null; this.persist() },
    persist() { try { localStorage.setItem(KEY, JSON.stringify({ items: this.items, checkoutKey: this.checkoutKey })) } catch { /* sem storage */ } },
  },
})
