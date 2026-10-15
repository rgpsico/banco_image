import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
  { path: '/register', name: 'register', component: () => import('../views/RegisterView.vue'), meta: { guest: true } },
  { path: '/forgot-password', name: 'forgot', component: () => import('../views/ForgotPasswordView.vue'), meta: { guest: true } },
  { path: '/reset-password', name: 'reset', component: () => import('../views/ResetPasswordView.vue'), meta: { guest: true } },

  // Projetos e biblioteca (privados)
  { path: '/', name: 'dashboard', component: () => import('../views/DashboardView.vue'), meta: { auth: true } },
  { path: '/library', name: 'library', component: () => import('../views/LibraryView.vue'), meta: { auth: true } },
  { path: '/members', name: 'members', component: () => import('../views/MembersView.vue'), meta: { auth: true } },
  { path: '/settings', name: 'settings', component: () => import('../views/SettingsView.vue'), meta: { auth: true } },

  // Marketplace público
  { path: '/market', name: 'market', component: () => import('../views/market/MarketHome.vue') },
  { path: '/market/search', name: 'market-search', component: () => import('../views/market/MarketSearch.vue') },
  { path: '/market/listing/:id', name: 'listing', component: () => import('../views/market/ListingDetail.vue') },
  { path: '/market/seller/:slug', name: 'seller-public', component: () => import('../views/market/SellerPublicView.vue') },
  { path: '/cart', name: 'cart', component: () => import('../views/market/CartView.vue') },
  { path: '/purchases', name: 'purchases', component: () => import('../views/market/PurchasesView.vue'), meta: { auth: true } },

  // Vendedor
  { path: '/seller/join', name: 'seller-join', component: () => import('../views/seller/SellerJoinView.vue'), meta: { auth: true } },
  { path: '/seller', name: 'seller', component: () => import('../views/seller/SellerDashboardView.vue'), meta: { auth: true } },
  { path: '/seller/listings', name: 'seller-listings', component: () => import('../views/seller/SellerListingsView.vue'), meta: { auth: true } },
  { path: '/seller/listings/new', name: 'seller-listing-new', component: () => import('../views/seller/SellerListingFormView.vue'), meta: { auth: true } },
  { path: '/seller/listings/:id', name: 'seller-listing-edit', component: () => import('../views/seller/SellerListingFormView.vue'), meta: { auth: true } },
  { path: '/seller/sales', name: 'seller-sales', component: () => import('../views/seller/SellerSalesView.vue'), meta: { auth: true } },

  // Moderação (o servidor impõe a permissão; aqui só exige login)
  { path: '/admin', name: 'admin', component: () => import('../views/admin/AdminView.vue'), meta: { auth: true } },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../views/NotFoundView.vue') },
]

const router = createRouter({ history: createWebHistory(), routes, scrollBehavior: () => ({ top: 0 }) })

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.ready) await auth.fetchMe()
  if (to.meta.auth && !auth.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } }
  if (to.meta.guest && auth.isAuthenticated) return { name: 'market' }
})

export default router
