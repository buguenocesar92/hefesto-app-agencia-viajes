// Tabla de asientos de un vuelo.
//
// ESTA PANTALLA TIENE QUE SER HONESTA CON LOS ESTADOS
// ---------------------------------------------------
// El mapa se arma LEYENDO las filas del servidor, no preguntando a un cache
// local. Si un asiento dice "disponible" en pantalla, es que la base de datos
// dice que esta disponible en este momento.
//
// Cuando dos agentes toman el mismo asiento a la vez, el segundo recibe un
// 409 y la pantalla lo muestra como mensaje, no como error generico: el
// operador tiene que entender que el eligio un asiento que otro acaba de
// tomar. Ver docs/01, seccion "Bloqueo de asientos".

<script setup lang="ts">
import type { Asiento } from '~/tipos/api';

const props = defineProps<{
  vueloId: string;
  asientos: Asiento[];
}>();

const emit = defineEmits<{
  tomar: [numero: string];
}>();

const aviso = ref<string | null>(null);

const porFila = computed(() => {
  const filas = new Map<number, Asiento[]>();

  for (const asiento of props.asientos) {
    const fila = Number(asiento.numero.replace(/[A-Z]/g, '')) || 0;

    if (!filas.has(fila)) {
      filas.set(fila, []);
    }

    filas.get(fila)!.push(asiento);
  }

  return Array.from(filas.entries()).sort((a, b) => a[0] - b[0]);
});

function pedir(asiento: Asiento): void {
  aviso.value = null;

  if (!asiento.disponible) {
    aviso.value = `El asiento ${asiento.numero} esta ${asiento.estado_etiqueta.toLowerCase()}.`;

    return;
  }

  emit('tomar', asiento.numero);
}

defineExpose({ aviso });
</script>

<template>
  <div class="mapa">
    <p v-if="aviso" class="con-aviso">{{ aviso }}</p>

    <div v-for="[fila, asientos] in porFila" :key="fila" class="fila">
      <span class="numero-fila">{{ fila }}</span>

      <button
        v-for="asiento in asientos"
        :key="asiento.id"
        type="button"
        class="asiento"
        :class="{
          libre: asiento.disponible,
          tomado: asiento.ocupado,
          fuera: asiento.estado === 'no_disponible',
        }"
        :disabled="asiento.estado === 'no_disponible'"
        :title="asiento.estado_etiqueta"
        @click="pedir(asiento)"
      >
        {{ asiento.numero }}
      </button>
    </div>

    <ul class="leyenda">
      <li><span class="libre"></span> Disponible</li>
      <li><span class="tomado"></span> Ocupado</li>
      <li><span class="fuera"></span> No disponible</li>
    </ul>
  </div>
</template>

<style scoped>
.fila {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  margin-bottom: 0.375rem;
}

.numero-fila {
  width: 2rem;
  color: #667085;
  font-size: 0.8125rem;
  text-align: right;
  margin-right: 0.5rem;
}

.asiento {
  width: 3rem;
  padding: 0.5rem 0;
  border: 1px solid var(--linea);
  border-radius: 0.375rem;
  background: white;
  font-size: 0.8125rem;
  cursor: pointer;
}

.asiento.libre:hover {
  border-color: var(--marca);
  background: #eff6ff;
}

.asiento.tomado {
  background: #e5e7eb;
  color: #6b7280;
  cursor: not-allowed;
}

.asiento.fuera {
  background: #f3f4f6;
  color: #9ca3af;
  cursor: not-allowed;
  text-decoration: line-through;
}

.leyenda {
  display: flex;
  gap: 1rem;
  list-style: none;
  padding: 0;
  margin-top: 1rem;
  font-size: 0.8125rem;
  color: #475467;
}

.leyenda span {
  display: inline-block;
  width: 0.75rem;
  height: 0.75rem;
  border: 1px solid var(--linea);
  border-radius: 0.1875rem;
  margin-right: 0.25rem;
}
</style>