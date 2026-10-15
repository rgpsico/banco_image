<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { parseError } from '../services/api'
import FormField from '../components/FormField.vue'
import FormAlert from '../components/FormAlert.vue'
import LoadingButton from '../components/LoadingButton.vue'

const auth = useAuthStore()
const router = useRouter()
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })
const loading = ref(false)
const error = ref('')
const fields = ref({})

async function submit() {
  loading.value = true; error.value = ''; fields.value = {}
  try {
    await auth.register(form)
    router.push({ name: 'market' })
  } catch (e) {
    const p = parseError(e); error.value = p.message; fields.value = p.fields
  } finally { loading.value = false }
}
</script>

<template>
  <div class="card auth-card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 brand mb-4">Criar conta</h1>
      <FormAlert :message="error" />
      <form @submit.prevent="submit">
        <FormField v-model="form.name" label="Nome" autocomplete="name" :error="fields.name" required />
        <FormField v-model="form.email" label="E-mail" type="email" autocomplete="email" :error="fields.email" required />
        <FormField v-model="form.password" label="Senha" type="password" autocomplete="new-password" :error="fields.password" required />
        <div class="form-text mb-3">Mínimo de 10 caracteres, com maiúsculas, minúsculas e números.</div>
        <FormField v-model="form.password_confirmation" label="Confirmar senha" type="password" autocomplete="new-password" required />
        <LoadingButton :loading="loading" class="w-100">Criar conta</LoadingButton>
      </form>
      <p class="mt-3 mb-0 small text-center">Já tem conta? <RouterLink :to="{ name: 'login' }">Entrar</RouterLink></p>
    </div>
  </div>
</template>
