// Configuracion de Nuxt (capa web) de la aplicacion para agencias de viajes.
//
// ARQUITECTURA BFF
// ----------------
// Este servidor es un "backend for frontend": la API de Laravel es la
// autoridad de la sesion y el navegador nunca habla directo con ella.
//
// El motivo es concreto: la sesion vive en una cookie HttpOnly + Secure +
// SameSite=Lax. Si el navegador llamara a otra origen (la API), la cookie no
// viajaria y habria que resolver el problema con CORS y credenciales, que
// duplica el riesgo de CSRF. Al pasar todo por el mismo origen (este servidor
// reenvia a la API), la cookie se mantiene en una sola origen y el token CSRF
// se reenvia como cabecera `X-XSRF-TOKEN`.
//
// Ver docs/01, seccion "Arquitectura BFF".

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  ssr: true,

  devtools: { enabled: false },

  runtimeConfig: {
    // URL de la API de Laravel. Es config PRIVADA: solo la usa el servidor.
    // Nunca debe exponerse al navegador.
    apiBase: process.env.NUXT_API_BASE || 'http://localhost:8000',

    public: {
      basePublico: process.env.NUXT_PUBLIC_BASE || 'http://localhost:3000',
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'es-CL' },
      title: 'Agencia de Viajes',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        {
          name: 'description',
          content: 'Venta de pasajes, reservas, documentacion de viaje y control de asientos.',
        },
      ],
    },
  },

  // Las solicitudes de datos pasan por el BFF de `server/api`, no por el
  // navegador. El cliente solo llama a rutas del mismo origen.
  nitro: {
    routeRules: {
      '/api/**': { cors: false },
    },
  },
});