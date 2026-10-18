# ---------------------------------------------------------------------------
# Imagen de la interfaz (Nuxt con renderizado en el servidor).
# ---------------------------------------------------------------------------
#
# VERSIONES FIJADAS, A PROPOSITO
# -----------------------------
# `node:22.14.0-alpine` con `alpine` fijada. Un `node:22-alpine` se actualiza
# solo entre construcciones, que es un despliegue que cambia sin que nadie lo
# decida.

# --- Etapa 1: dependencias -------------------------------------------------
FROM node:22.14.0-alpine3.21 AS dependencias

WORKDIR /app

COPY package.json package-lock.json* ./

RUN npm ci --no-audit --no-fund

# --- Etapa 2: construccion -------------------------------------------------
FROM node:22.14.0-alpine3.21 AS construccion

WORKDIR /app

COPY --from=dependencias /app/node_modules ./node_modules
COPY . .

# `nuxt build` deja el resultado en `.output`. Es lo unico que se copia a la
# etapa final: ni el codigo fuente ni las dependencias de desarrollo.
RUN npm run build

# --- Etapa 3: ejecucion ----------------------------------------------------
FROM node:22.14.0-alpine3.21 AS ejecucion

WORKDIR /app

ENV NODE_ENV=production
ENV NITRO_PORT=3000
ENV NITRO_HOST=0.0.0.0

# El servidor de Nuxt corre sin permisos de root.
RUN addgroup -g 1001 -S nuxt && adduser -u 1001 -S nuxt -G nuxt

COPY --from=construccion --chown=nuxt:nuxt /app/.output ./.output

USER nuxt

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]