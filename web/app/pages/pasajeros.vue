// Lista de pasajeros.
//
// LO QUE ESTA PANTALLA MUESTRA Y LO QUE NO
// -----------------------------------------
// Muestra el nombre que el pasajero DECLARO y sus documentos CON EL NUMERO
// ENMASCARADO. No muestra el numero entero: verlo exige pedirlo a proposito,
// con un motivo, y queda registrado en la bitacora de accesos.
//
// Que el enmascarado se vea en la pantalla, y no solo en el Resource, es a
// proposito: el listado es el lugar por donde se escapan los datos.

<script setup lang="ts">
import type { Pasajero } from '~/tipos/api';

definePageMeta({ middleware: 'agente' });

const busqueda = ref('');

const { data } = await useAsyncData(
  'pasajeros',
  () => $fetch<{ data: Pasajero[] }>('/api/v1/pasajeros'),
);

const pasajeros = computed(() => {
  const todos = data.value?.data ?? [];
  const termino = busqueda.value.trim().toLowerCase();

  if (termino === '') {
    return todos;
  }

  return todos.filter((p) => p.nombre_declarado.toLowerCase().includes(termino));
});
</script>

<template>
  <div>
    <h1>Pasajeros</h1>

    <input v-model="busqueda" type="search" placeholder="Buscar por nombre declarado">

    <table>
      <thead>
        <tr>
          <th>Nombre declarado</th>
          <th>Documentos</th>
          <th>Viajes</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="pasajero in pasajeros" :key="pasajero.id">
          <td>{{ pasajero.nombre_declarado }}</td>
          <td>
            <div v-for="documento in pasajero.documentos" :key="documento.id" class="documento">
              <span class="numero">{{ documento.numero_enmascarado }}</span>
              <span>{{ documento.tipo_etiqueta }}</span>
              <span>{{ documento.nombres }} {{ documento.apellidos }}</span>

              <span
                v-if="documento.vencido"
                class="con-aviso"
              >Vencido</span>

              <span
                v-if="documento.estado_verificacion_nombre !== 'verificado'"
                class="con-aviso"
              >
                {{ documento.estado_verificacion_etiqueta }}
              </span>
            </div>
          </td>
          <td>{{ pasajero.viajes }}</td>
        </tr>
      </tbody>
    </table>

    <p v-if="pasajeros.length === 0" class="vacio">No hay pasajeros que coincidan.</p>
  </div>
</template>

<style scoped>
input {
  padding: 0.5rem;
  margin-bottom: 1rem;
  width: 100%;
  max-width: 24rem;
}

.documento {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  font-size: 0.8125rem;
  margin-bottom: 0.25rem;
}

.numero {
  font-family: ui-monospace, monospace;
  color: #475467;
}

.vacio {
  padding: 2rem;
  color: #667085;
  text-align: center;
}
</style>