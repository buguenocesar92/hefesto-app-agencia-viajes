// Composable de sesion.
//
// El navegador NUNCA habla directo con la API de Laravel: todo pasa por el
// backend-for-frontend de `server/api`, que es el unico que tiene la cookie de
// sesion. Ver docs/01, seccion "Arquitectura BFF".

import type { Usuario } from '~/tipos/api';

export function useSesion() {
  const usuario = useState<Usuario | null>('sesion.usuario', () => null);

  const cargada = useState<boolean>('sesion.cargada', () => false);

  async function cargar(): Promise<Usuario | null> {
    try {
      const respuesta = await $fetch<{ usuario: Usuario | null }>('/api/v1/sesion');

      usuario.value = respuesta.usuario ?? null;
    } catch {
      // Un 401 o 403 no es un error de la pantalla: es que no hay sesion, y la
      // pantalla de entrada es la respuesta correcta.
      usuario.value = null;
    } finally {
      cargada.value = true;
    }

    return usuario.value;
  }

  async function salir(): Promise<void> {
    // El cierre de sesion es un POST, no un GET: un GET se podria disparar
    // desde otro sitio con una imagen. Ver docs/07, seccion "CSRF".
    await $fetch('/auth/salir', { method: 'POST' });

    usuario.value = null;

    await navigateTo('/entrar');
  }

  const esAdministrador = computed(() => usuario.value?.rol === 'administrador');

  return { usuario, cargada, cargar, salir, esAdministrador };
}