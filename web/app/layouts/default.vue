<template>
  <div class="aplicacion">
    <header class="barra">
      <NuxtLink to="/" class="marca">Agencia de Viajes</NuxtLink>

      <nav v-if="sesion.usuario.value" class="navegacion">
        <NuxtLink to="/reservas">Reservas</NuxtLink>
        <NuxtLink to="/pagos">Pagos</NuxtLink>
        <NuxtLink to="/pasajeros">Pasajeros</NuxtLink>
        <NuxtLink to="/vuelos">Vuelos</NuxtLink>
      </nav>

      <div class="sesion">
        <template v-if="sesion.usuario.value">
          <span class="agente">{{ sesion.usuario.value.nombre }}</span>
          <span class="agencia">{{ sesion.usuario.value.agencia }}</span>
          <button type="button" @click="salir">Salir</button>
        </template>
        <NuxtLink v-else to="/entrar">Ingresar</NuxtLink>
      </div>
    </header>

    <main class="contenido">
      <slot />
    </main>

    <footer class="pie">
      <p>
        Software de Hefesto Studio. Los datos de los pasajeros son datos
        personales y se tratan conforme a la Ley 19.628.
      </p>
    </footer>
  </div>
</template>

<script setup lang="ts">
const sesion = useSesion();
const { salir } = sesion;

// Se carga la sesion en el servidor para que la barra no "salte" al
// hidratarse.
await useAsyncData('sesion', async () => {
  await sesion.cargar();

  return true;
});
</script>

<style>
:root {
  --tinta: #1d2733;
  --papel: #f7f8fa;
  --linea: #d8dee6;
  --marca: #1f5f8b;
  --aviso: #9a3412;
  --bien: #14532d;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: var(--tinta);
  background: var(--papel);
}

.barra {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  padding: 0.75rem 1.5rem;
  background: white;
  border-bottom: 1px solid var(--linea);
}

.marca {
  font-weight: 700;
  color: var(--marca);
  text-decoration: none;
}

.navegacion {
  display: flex;
  gap: 1rem;
  flex: 1;
}

.navegacion a {
  color: var(--tinta);
  text-decoration: none;
}

.navegacion a.router-link-active {
  font-weight: 600;
  color: var(--marca);
}

.sesion {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
}

.agencia {
  color: #667085;
}

.contenido {
  max-width: 78rem;
  margin: 0 auto;
  padding: 1.5rem;
}

.pie {
  padding: 1rem 1.5rem;
  color: #667085;
  font-size: 0.8125rem;
  border-top: 1px solid var(--linea);
}

table {
  width: 100%;
  border-collapse: collapse;
  background: white;
}

th,
td {
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid var(--linea);
  text-align: left;
  font-size: 0.9375rem;
}

th {
  font-weight: 600;
  color: #475467;
}

.con-aviso {
  color: var(--aviso);
  font-weight: 600;
}

.con-alerta {
  background: #fef3c7;
}
</style>