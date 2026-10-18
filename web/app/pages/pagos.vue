// Pantalla de pagos.
//
// Esta pantalla existe para una sola cosa: que un pasaje pagado a medias no
// pase inadvertido. Por eso arranca ordenada por antiguedad y sin filtro
// aplicado: lo primero que hay que ver es lo que lleva mas tiempo sin cobrar.

<script setup lang="ts">
import { aPesos } from '~/tipos/api';
import type { Reserva } from '~/tipos/api';

definePageMeta({ middleware: 'agente' });

const { data, refresh } = await useAsyncData(
  'pagos-incompletos',
  () => $fetch<{ data: Reserva[] }>('/api/v1/pagos/incompletos'),
);

const totalPendiente = computed(() =>
  (data.value?.data ?? []).reduce((suma, reserva) => suma + reserva.saldo, 0),
);

const aviso = ref<string | null>(null);
const reservaActiva = ref<string | null>(null);
const monto = ref<number | null>(null);

async function registrar(reserva: Reserva): Promise<void> {
  if (reservaActiva.value !== reserva.id) {
    reservaActiva.value = reserva.id;
    monto.value = null;

    return;
  }

  aviso.value = null;

  if (!monto.value || monto.value <= 0) {
    aviso.value = 'Indique el monto del pago.';

    return;
  }

  try {
    // La API pide CENTIMOS. La pantalla muestra pesos, y la conversion se hace
    // aca, una sola vez.
    await $fetch(`/api/v1/reservas/${reserva.id}/pagos`, {
      method: 'POST',
      body: {
        monto_centimos: Math.round(monto.value * 100),
        metodo: 'efectivo',
      },
    });

    aviso.value = `Pago de ${aPesos(Math.round(monto.value * 100))} registrado en ${reserva.folio}.`;

    reservaActiva.value = null;
    monto.value = null;

    await refresh();
  } catch (error: unknown) {
    const fallo = error as { data?: { mensaje?: string } };

    aviso.value = fallo?.data?.mensaje ?? 'No se pudo registrar el pago.';
  }
}
</script>

<template>
  <div>
    <h1>Pagos incompletos</h1>

    <p class="resumen">
      {{ data?.data.length ?? 0 }} reservas con saldo pendiente, por un total
      de <strong>{{ aPesos(totalPendiente) }}</strong>.
    </p>

    <p v-if="aviso" class="con-aviso">{{ aviso }}</p>

    <table>
      <thead>
        <tr>
          <th>Folio</th>
          <th>Vuelo</th>
          <th>Fecha de venta</th>
          <th>Total</th>
          <th>Pagado</th>
          <th>Saldo</th>
          <th>Registrar pago</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="reserva in data?.data" :key="reserva.id">
          <td>{{ reserva.folio }}</td>
          <td>{{ reserva.vuelo?.tramo ?? reserva.producto.nombre }}</td>
          <td>{{ reserva.creado_en }}</td>
          <td>{{ aPesos(reserva.total) }}</td>
          <td>{{ aPesos(reserva.pagado) }}</td>
          <td class="con-aviso">{{ aPesos(reserva.saldo) }}</td>
          <td>
            <button type="button" @click="registrar(reserva)">
              {{ reservaActiva === reserva.id ? 'Confirmar' : 'Agregar pago' }}
            </button>

            <input
              v-if="reservaActiva === reserva.id"
              v-model.number="monto"
              type="number"
              min="1"
              step="1000"
              placeholder="Monto en pesos"
            >
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="(data?.data.length ?? 0) === 0" class="vacio">
      No hay pagos pendientes. Todas las reservas estan saldadas.
    </p>
  </div>
</template>

<style scoped>
.resumen {
  color: #475467;
}

.vacio {
  padding: 2rem;
  color: #667085;
  text-align: center;
}

input {
  display: block;
  margin-top: 0.375rem;
  padding: 0.375rem;
  width: 10rem;
}
</style>