// Lista de reservas.
//
// El filtro de "pagos incompletos" es el que hace util esta pantalla: es la
// forma de que un pasaje pagado a medias no pase inadvertido. Ver
// FilaReserva.

<script setup lang="ts">
import { aPesos } from '~/tipos/api';
import type { Reserva } from '~/tipos/api';

definePageMeta({ middleware: 'agente' });

const filtro = ref<'todas' | 'pagadas' | 'incompletas'>('todas');

const { data, refresh } = await useAsyncData(
  'reservas',
  () => $fetch<{ data: Reserva[] }>('/api/v1/reservas'),
);

const reservas = computed(() => {
  const todas = data.value?.data ?? [];

  if (filtro.value === 'pagadas') {
    return todas.filter((r) => r.estado_pago === 'pagada');
  }

  if (filtro.value === 'incompletas') {
    return todas.filter((r) => r.pago_incompleto);
  }

  return todas;
});

const aviso = ref<string | null>(null);

async function emitir(reserva: Reserva): Promise<void> {
  aviso.value = null;

  try {
    await $fetch(`/api/v1/reservas/${reserva.id}/estado`, {
      method: 'POST',
      body: { estado: 'emitida' },
    });

    aviso.value = `Boleto emitido para ${reserva.folio}.`;

    await refresh();
  } catch (error: unknown) {
    const fallo = error as { data?: { mensaje?: string } };

    aviso.value =
      fallo?.data?.mensaje ??
      'No se pudo emitir. Revise que todos los nombres esten verificados.';
  }
}
</script>

<template>
  <div>
    <h1>Reservas</h1>

    <div class="filtros">
      <button
        v-for="opcion in ['todas', 'pagadas', 'incompletas']"
        :key="opcion"
        type="button"
        :class="{ activo: filtro === opcion }"
        @click="filtro = opcion as typeof filtro"
      >
        {{ opcion }}
      </button>
    </div>

    <p v-if="aviso" :class="aviso.includes('No se pudo') ? 'con-aviso' : 'saldado'">{{ aviso }}</p>

    <table>
      <thead>
        <tr>
          <th>Folio</th>
          <th>Producto</th>
          <th>Estado</th>
          <th>Pasajeros y nombre del boleto</th>
          <th>Cuenta</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <FilaReserva
          v-for="reserva in reservas"
          :key="reserva.id"
          :reserva="reserva"
          @emitir="emitir(reserva)"
        />
      </tbody>
    </table>

    <p v-if="reservas.length === 0" class="vacio">
      No hay reservas que coincidan con el filtro.
    </p>
  </div>
</template>

<style scoped>
.filtros {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.filtros button {
  padding: 0.375rem 0.75rem;
  border: 1px solid var(--linea);
  border-radius: 0.375rem;
  background: white;
  cursor: pointer;
}

.filtros button.activo {
  background: var(--marca);
  color: white;
  border-color: var(--marca);
}

.vacio {
  padding: 2rem;
  color: #667085;
  text-align: center;
}

.saldado {
  color: var(--bien);
}
</style>