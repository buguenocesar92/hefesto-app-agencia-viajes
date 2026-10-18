// Vuelos y mapa de asientos.
//
// El mapa se arma leyendo las filas del servidor. Cuando dos agentes toman el
// mismo asiento al mismo tiempo, el segundo recibe un 409 con un mensaje que
// explica QUE PASO, para que el operador elija otro y no repita la operacion
// pensando que el sistema se caigo.

<script setup lang="ts">
import type { Asiento } from '~/tipos/api';

definePageMeta({ middleware: 'agente' });

const vueloSeleccionado = ref<string | null>(null);

const { data: vuelos } = await useAsyncData(
  'vuelos',
  () => $fetch<{ data: Array<{ id: string; codigo_vuelo: string; tramo: string; salida_en: string }> }>('/api/v1/vuelos'),
);

const { data: asientos, refresh } = await useAsyncData(
  'asientos',
  () =>
    vueloSeleccionado.value
      ? $fetch<{ data: Asiento[] }>(`/api/v1/vuelos/${vueloSeleccionado.value}/asientos`)
      : Promise.resolve({ data: [] as Asiento[] }),
  { watch: [vueloSeleccionado] },
);

const aviso = ref<string | null>(null);

async function tomar(numero: string): Promise<void> {
  if (!vueloSeleccionado.value) {
    return;
  }

  aviso.value = null;

  try {
    await $fetch(`/api/v1/vuelos/${vueloSeleccionado.value}/asientos/${numero}/tomar`, {
      method: 'POST',
      // `reserva_id` es obligatorio en la API: un asiento no se toma "al
      // vuelo", se toma para una reserva concreta. Esta pantalla pide elegir
      // la reserva antes de tomar, que es el flujo real del mostrador.
      body: { reserva_id: reservaSeleccionada.value },
    });

    aviso.value = `Asiento ${numero} tomado.`;

    await refresh();
  } catch (error: unknown) {
    const fallo = error as { status?: number; data?: { mensaje?: string } };

    aviso.value =
      fallo?.data?.mensaje ??
      (fallo?.status === 409
        ? 'Ese asiento fue tomado por otra compra al mismo tiempo. Elija otro.'
        : 'No se pudo tomar el asiento.');

    await refresh();
  }
}

const reservaSeleccionada = ref<string>('');
</script>

<template>
  <div>
    <h1>Vuelos</h1>

    <table>
      <thead>
        <tr>
          <th>Vuelo</th>
          <th>Tramo</th>
          <th>Salida</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="vuelo in vuelos?.data" :key="vuelo.id">
          <td>{{ vuelo.codigo_vuelo }}</td>
          <td>{{ vuelo.tramo }}</td>
          <td>{{ vuelo.salida_en }}</td>
          <td>
            <button
              type="button"
              :class="{ activo: vueloSeleccionado === vuelo.id }"
              @click="vueloSeleccionado = vuelo.id"
            >
              Ver asientos
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <section v-if="vueloSeleccionado" class="detalle">
      <h2>Mapa de asientos</h2>

      <!--
        El asiento se toma SIEMPRE para una reserva. Sin una reserva a la que
        pertenece, un asiento tomado no es de nadie y queda apretado hasta que
        expire el bloqueo.
      -->
      <label class="selector">
        Reserva a la que pertenece el asiento
        <input v-model="reservaSeleccionada" type="text" placeholder="Identificador de la reserva">
      </label>

      <p v-if="!reservaSeleccionada" class="ayuda">
        Indique la reserva antes de tomar un asiento.
      </p>

      <p v-if="aviso" class="con-aviso">{{ aviso }}</p>

      <TablaAsientos
        :vuelo-id="vueloSeleccionado"
        :asientos="asientos?.data ?? []"
        @tomar="tomar"
      />
    </section>
  </div>
</template>

<style scoped>
.detalle {
  margin-top: 2rem;
}

.selector {
  display: block;
  margin-bottom: 1rem;
  font-size: 0.875rem;
}

.selector input {
  display: block;
  margin-top: 0.25rem;
  padding: 0.375rem;
  width: 20rem;
}

.ayuda {
  color: #667085;
  font-size: 0.875rem;
}

button.activo {
  background: var(--marca);
  color: white;
  border-color: var(--marca);
}
</style>