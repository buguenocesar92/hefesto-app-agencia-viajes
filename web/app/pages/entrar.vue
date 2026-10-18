// Pantalla de entrada.
//
// Solo una cosa: entrar. El login real es OAuth contra Authentik y lo resuelve
// la API. Esta pantalla no pide contrasena ni la maneja en ningun momento:
// Hefesto Studio nunca ve la clave del agente.

<script setup lang="ts">
const error = ref<string | null>(null);

function entrar(): void {
  error.value = null;

  // Redireccion completa al proveedor de identidad. No es un `$fetch`: el
  // navegador tiene que navegar, no pedir datos.
  window.location.href = '/auth/redirect';
}
</script>

<template>
  <div class="entrada">
    <h1>Agencia de Viajes</h1>

    <p>
      El ingreso se hace con la cuenta corporativa de la agencia. Hefesto Studio
      no almacena contrasenas de agentes.
    </p>

    <p v-if="error" class="con-aviso">{{ error }}</p>

    <button type="button" class="principal" @click="entrar">Ingresar</button>

    <p class="ayuda">
      Si su cuenta esta en el proveedor de identidad pero no puede operar,
      pida al administrador de su agencia que la agregue.
    </p>
  </div>
</template>

<style scoped>
.entrada {
  max-width: 26rem;
  margin: 3rem auto;
  text-align: center;
}

.principal {
  width: 100%;
  padding: 0.75rem;
  margin: 1.5rem 0;
  background: var(--marca);
  color: white;
  border: none;
  border-radius: 0.375rem;
  font-size: 1rem;
  cursor: pointer;
}

.ayuda {
  font-size: 0.8125rem;
  color: #667085;
}
</style>