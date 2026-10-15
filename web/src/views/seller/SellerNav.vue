<template>
  <ul class="nav nav-pills mb-4 gap-1">
    <li class="nav-item"><RouterLink class="nav-link" to="/seller" exact-active-class="active">Painel</RouterLink></li>
    <li class="nav-item"><RouterLink class="nav-link" to="/seller/listings" active-class="active">Minhas publicações</RouterLink></li>
    <li class="nav-item"><RouterLink class="nav-link" to="/seller/sales" active-class="active">Vendas e comissões</RouterLink></li>
    <li class="nav-item ms-auto"><RouterLink class="btn btn-primary btn-sm" to="/seller/listings/new">+ Nova publicação</RouterLink></li>
  </ul>
</template>
