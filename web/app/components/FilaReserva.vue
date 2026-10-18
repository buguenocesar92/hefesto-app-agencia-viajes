// Fila de una reserva.
//
// LO QUE ESTA PANTALLA HACE VISIBLE, Y POR QUE
// ---------------------------------------------
// 1. EL SALDO. Siempre. Una pantalla de reservas sin saldo es una pantalla
//    donde se pierde plata sin que nadie lo note.
// 2. EL PAGO INCOMPLETO, resaltado. El problema clasico.
// 3. EL ESTADO DE VERIFICACION DEL NOMBRE. El producto.
//
// LO QUE NO HACE
// --------------
// No muestra el documento del pasajero. La API devuelve el numero
// ENMASCARADO y esta pantalla no pide el numero entero: verlo exige un motivo
// y queda en la bitacora. Ver docs/07.

<script setup lang="ts">
import { aPesos } from '~/tipos/api';
import type { Reserva } from '~/tipos/api';

defineProps<{ reserva: Reserva }>();

const emit = defineEmits<{
  verificar: [pasajeroId: string];
  emitir: [];
}>();
</script>

<template>
  <tr :class="{ 'con-alerta': reserva.pago_incompleto }">
    <td>{{ reserva.folio }}</td>

    <td>
      <strong>{{ reserva.producto.nombre ?? 'Sin producto' }}</strong>
      <div v-if="reserva.vuelo" class="detalle">
        {{ reserva.vuelo.codigo_vuelo }} · {{ reserva.vuelo.tramo }} ·
        {{ reserva.vuelo.salida_en }}
      </div>
    </td>

    <td>
      <span class="etiqueta">{{ reserva.estado_etiqueta }}</span>
    </td>

    <td>
      <div v-for="pasajero in reserva.pasajeros" :key="pasajero.id" class="pasajero">
        <span>{{ pasajero.nombre_boleto }}</span>

        <!--
          La diferencia entre el nombre del boleto y como se llamaba el
          pasajero es justamente lo que un agente tiene que mirar. El sistema
          avisa; la persona decide.
        -->
        <span
          class="verificacion"
          :class="{
            'con-aviso': pasajero.estado_verificacion_nombre !== 'verificado',
          }"
        >
          {{ pasajero.estado_verificacion_etiqueta }}
        </span>

        <span v-if="pasajero.asiento" class="asiento">Asiento {{ pasajero.asiento }}</span>
      </div>
    </td>

    <td class="montos">
      <div>Total {{ aPesos(reserva.total) }}</div>
      <div>Pagado {{ aPesos(reserva.pagado) }}</div>

      <div :class="reserva.pago_incompleto ? 'con-aviso' : 'saldado'">
        Saldo {{ aPesos(reserva.saldo) }}
      </div>
    </td>

    <td>
      <button
        v-if="reserva.estado === 'confirmada'"
        type="button"
        :disabled="reserva.pasajeros.some((p) => !p.puede_emitir)"
        @click="emit('emitir')"
      >
        Emitir boleto
      </button>

      <button type="button" @click="emit('verificar', reserva.id)">Revisar nombres</button>
    </td>
  </tr>
</template>

<style scoped>
.detalle {
  color: #667085;
  font-size: 0.8125rem;
}

.pasajero {
  display: flex;
  flex-direction: column;
  margin-bottom: 0.5rem;
}

.verificacion {
  font-size: 0.75rem;
  color: #667085;
}

.asiento {
  font-size: 0.75rem;
  color: var(--marca);
}

.montos {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.saldado {
  color: var(--bien);
  font-weight: 600;
}

.etiqueta {
  font-size: 0.8125rem;
}
</style>