// Proxy del navegador a la API de Laravel (backend-for-frontend).
//
// POR QUE ESTE ARCHIVO EXISTE
// ---------------------------
// El navegador nunca habla directo con la API. Este es el unico que tiene la
// cookie de sesion, y el unico que puede mandarle el token CSRF.
//
// Que el navegador no hable directo importa por dos razones de seguridad:
//  1. La cookie de sesion se mantiene en UNA sola origen, asi que no hay que
//     abrir CORS con credenciales.
//  2. El token CSRF se reenvia como cabecera `X-XSRF-TOKEN` sin que el
//     navegador tenga que leer la cookie HttpOnly.
//
// LO QUE SE REENVIA Y LO QUE NO
// -----------------------------
// Se reenvian `cookie`, `X-XSRF-TOKEN` y `X-Motivo-Consulta` (el motivo por el
// que un agente pide ver un documento). NO se reenvia nada mas del navegador:
// cualquier cabecera que llegue y no este en esta lista se descarta.

import type { H3Event } from 'h3';

const CABECERAS_PERMITIDAS = ['cookie', 'x-xsrf-token', 'x-motivo-consulta', 'accept-language'];

export default defineEventHandler(async (event: H3Event) => {
  const config = useRuntimeConfig(event);

  const ruta = getRequestURL(event).pathname.replace(/^\/api\//, '');
  const consulta = getRequestURL(event).search;

  const cabeceras: Record<string, string> = {};

  for (const nombre of CABECERAS_PERMITIDAS) {
    const valor = getRequestHeader(event, nombre);

    if (valor !== undefined) {
      cabeceras[nombre] = valor;
    }
  }

  if (event.method === 'POST' || event.method === 'PUT' || event.method === 'DELETE') {
    cabeceras['content-type'] = getRequestHeader(event, 'content-type') ?? 'application/json';
  }

  const cuerpo = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(event.method)
    ? await readBody(event)
    : undefined;

  try {
    return await $fetch.raw(`${config.apiBase}/api/v1/${ruta}${consulta}`, {
      method: event.method as 'GET',
      headers: cabeceras,
      // El cuerpo se pasa como texto para que el reenvio sea literal: si se
      // reenviase parseado, un error de tipos al serializar cambiaria lo que
      // la API recibe.
      body: cuerpo as never,
    }).then((respuesta) => {
      setResponseStatus(event, respuesta.status);

      return respuesta._data;
    });
  } catch (error: unknown) {
    const fallo = error as { response?: { status?: number; _data?: unknown } };

    if (fallo.response) {
      // El error de la API se reenvia tal cual: el mensaje que escribe la API
      // esta escrito para el operador, y pasar por el proxy no le quita ni
      // agrega nada.
      setResponseStatus(event, fallo.response.status ?? 500);

      return fallo.response._data;
    }

    throw createError({
      statusCode: 502,
      statusMessage: 'No se pudo comunicar con la API de viajes.',
    });
  }
});