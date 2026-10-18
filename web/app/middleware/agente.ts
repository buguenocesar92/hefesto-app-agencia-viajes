// Middleware de rutas que exigen agente.
//
// Va en el servidor: si la sesion no esta, se responde con una redireccion
// desde el servidor y el navegador ni siquiera llega a renderizar la pantalla
// de datos.

export default defineNuxtRouteMiddleware(async () => {
  const { usuario, cargar } = useSesion();

  if (!usuario.value) {
    await cargar();
  }

  if (!usuario.value) {
    return navigateTo('/entrar');
  }
});