// Pantalla principal.
//
// Es el punto de entrada del dia a dia. Dice, de un vistazo, las tres cosas
// que arden en una agencia:
//   1. las reservas pendientes de verificar el nombre del boleto,
//   2. los pagos incompletos,
//   3. los vuelos que salen pronto.

<script setup lang="ts">
import { aPesos } from '~/tipos/api';

const { data } = await useAsyncData('panel', async () => {
  const [reservas, pagos, vuelos] = await Promise.all([
    $fetch<{ data: any[] }>('/api/v1/reservas'),
    $fetch<{ data: any[] }>('/api/v1/pagos/incompletos'),
    $fetch<{ data: any[] }>('/api/v1/vuelos'),
  ]);

  return {
    reservas: reservas.data ?? [],
    pagosIncompletos: pagos.data ?? [],
    vuelos: vuelos.data ?? [],
  };
});

const porVerificar = computed(() =>
  (data.value?.reservas ?? []).filter((r) =>
    r.estado === 'confirmada' && r.pasajeros.some((p: any) => !p.puede_emitir),
  ),
);

const dineroIncompleto = computed(() =>
  (data.value?.pagosIncompletos ?? []).reduce(
    (suma: number, reserva: any) => suma + (reserva.saldo ?? 0),
    0,
  ),
);
</script>

<template>
  <div>
    <h1>Panel de la agencia</h1>

    <section class="tarjetas">
      <article class="tarjeta">
        <h2>Por verificar</h2>
        <p class="cifra">{{ porVerificar.length }}</p>
        <p class="ayuda">
          Reservas confirmadas con nombres de boleto sin revisar. No se pueden
          emitir hasta que un agente los verifique.
        </p>
      </article>

      <article class="tarjeta">
        <h2>Pagos incompletos</h2>
        <p class="cifra">{{ data?.pagosIncompletos.length ?? 0 }}</p>
        <p class="ayuda">{{ aPesos(dineroIncompleto) }} pendientes de cobro.</p>
      </article>

      <article class="tarjeta">
        <h2>Vuelos por salir</h2>
        <p class="cifra">{{ data?.vuelos.length ?? 0 }}</p>
        <p class="ayuda">Con plazas disponibles.</p>
      </article>
    </section>

    <section>
      <h2>Proximas salidas</h2>

      <table>
        <thead>
          <tr>
            <th>Vuelo</th>
            <th>Tramo</th>
            <th>Salida</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="vuelo in data?.vuelos" :key="vuelo.id">
            <td>{{ vuelo.codigo_vuelo }}</td>
            <td>{{ vuelo.tramo }}</td>
            <td>{{ vuelo.salida_en }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<style scoped>
.tarjetas {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
  gap: 1rem;
  margin-bottom: 2rem;
}

.tarjeta {
  background: white;
  border: 1px solid var(--linea);
  border-radius: 0.5rem;
  padding: 1rem;
}

.tarjeta h2 {
  font-size: 0.875rem;
  color: #475467;
  margin: 0;
}

.cifra {
  font-size: 2rem;
  font-weight: 700;
  margin: 0.25rem 0;
}

.ayuda {
  font-size: 0.8125rem;
  color: #667085;
  margin: 0;
}
</style>