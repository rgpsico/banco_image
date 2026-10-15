<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSellerStore } from '../../stores/seller'
import { useTenantStore } from '../../stores/tenant'
import { parseError } from '../../services/api'
import FormField from '../../components/FormField.vue'
import FormAlert from '../../components/FormAlert.vue'
import LoadingButton from '../../components/LoadingButton.vue'

const seller = useSellerStore()
const tenants = useTenantStore()
const router = useRouter()
const form = reactive({ display_name: '', bio: '', accept_terms: false })
const loading = ref(false)
const error = ref('')
const fields = ref({})

if (seller.profile) router.replace({ name: 'seller' })

async function submit() {
  loading.value = true; error.value = ''; fields.value = {}
  try {
    await seller.join(form)
    // Sem nenhum projeto ainda? Cria um espaço pessoal para guardar as fotos.
    if (!tenants.tenants.length) { await tenants.fetch(); if (!tenants.tenants.length) await tenants.create('Meu estúdio') }
    router.push({ name: 'seller' })
  } catch (e) { const p = parseError(e); error.value = p.message; fields.value = p.fields } finally { loading.value = false }
}
</script>

<template>
  <div style="max-width: 640px" class="mx-auto">
    <h1 class="h4">Venda suas fotos e vídeos</h1>
    <p class="text-muted">Envie suas mídias, defina os preços e as licenças, e receba por cada venda. A plataforma retém uma comissão (padrão de 20%) e analisa cada publicação antes de ela ir ao ar.</p>
    <FormAlert :message="error" />
    <div class="card shadow-sm"><div class="card-body">
      <form @submit.prevent="submit">
        <FormField v-model="form.display_name" label="Nome público (aparece no seu perfil)" :error="fields.display_name" required />
        <div class="mb-3">
          <label class="form-label">Sobre você (opcional)</label>
          <textarea v-model="form.bio" class="form-control" rows="3" maxlength="500"></textarea>
        </div>
        <div class="form-check mb-3" :class="{ 'is-invalid': fields.accept_terms }">
          <input id="terms" v-model="form.accept_terms" class="form-check-input" type="checkbox" />
          <label for="terms" class="form-check-label small">
            Declaro que serei o <strong>autor ou titular dos direitos</strong> de tudo o que eu publicar, que tenho <strong>autorização de uso de imagem</strong>
            das pessoas reconhecíveis que aparecerem, e que concordo com a moderação e com a comissão da plataforma.
          </label>
          <div v-if="fields.accept_terms" class="text-danger small">{{ fields.accept_terms }}</div>
        </div>
        <LoadingButton :loading="loading">Tornar-me vendedor</LoadingButton>
      </form>
    </div></div>
  </div>
</template>
