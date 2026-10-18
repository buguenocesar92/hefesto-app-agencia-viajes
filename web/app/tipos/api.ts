// Tipos de la capa web.
//
// LO QUE ESTA EN ESTE ARCHIVO Y LO QUE NO
// ---------------------------------------
// Los tipos que reflejan la respuesta de la API. Se replican a mano en vez de
// generarse, porque son pocos y porque un tipo generado automaticamente no
// dice NADA de lo que un campo significa.
//
// En un tipo hay dos cosas que hay que escribir y no se pueden quitar:
//  - por que el campo existe;
//  - que dato personal puede haber.
//
// `numeroEnmascarado` es el ejemplo: el documento de un pasajero se ve
// enmascarado en toda la interfaz, y por eso el tipo se llama asi y no
// `numero`. Ver docs/07.

export type EstadoReserva = 'pendiente' | 'confirmada' | 'emitida' | 'usada' | 'cancelada';

export type EstadoPago = 'pendiente' | 'parcial' | 'pagada' | 'anulada';

export type EstadoAsiento = 'disponible' | 'bloqueado' | 'asignado' | 'liberado' | 'no_disponible';

/**
 * Estado de la revision del nombre del boleto.
 *
 * `pendiente` es el estado normal de casi todo boleto nuevo: el sistema armo
 * el nombre desde el documento y espera que un agente lo mire.
 */
export type EstadoVerificacionNombre = 'pendiente' | 'verificado' | 'rechazado';

export interface Usuario {
  id: string;
  nombre: string;
  email: string;
  rol: 'agente' | 'administrador';
  agencia: string;
}

/**
 * Documento de viaje en la interfaz.
 *
 * `numeroEnmascarado` y NUNCA `numero`. El numero completo solo llega en la
 * respuesta del endpoint que exige motivo y que queda auditado, y esa
 * respuesta no se guarda en ningun estado de la interfaz.
 */
export interface DocumentoViaje {
  id: string;
  tipo: 'pasaporte' | 'cedula' | 'documento_de_viaje' | 'dni';
  tipo_etiqueta: string;
  numero_enmascarado: string;
  nombres: string;
  apellidos: string;
  fecha_vencimiento: string | null;
  vencido: boolean;
  estado_verificacion_nombre: EstadoVerificacionNombre;
  estado_verificacion_etiqueta: string;
  verificado_en: string | null;
  puede_emitir: boolean;
}

export interface Pasajero {
  id: string;
  nombre_declarado: string;
  nacionalidad: string | null;
  viajes: number;
  documentos: DocumentoViaje[];
}

export interface PasajeroEnReserva {
  id: string;
  pasajero_id: string;
  /**
   * El nombre del BOLETO. Sale del documento, no de como el pasajero se
   * escribio a si mismo. Ver docs/01, seccion "El nombre del boleto".
   */
  nombre_boleto: string;
  estado_verificacion_nombre: EstadoVerificacionNombre;
  estado_verificacion_etiqueta: string;
  puede_emitir: boolean;
  es_contacto: boolean;
  asiento: string | null;
}

export interface Reserva {
  id: string;
  folio: string;
  producto: {
    id: string;
    nombre: string | null;
    tipo: string;
  };
  vuelo: {
    id: string;
    codigo_vuelo: string;
    tramo: string;
    salida_en: string;
  } | null;
  estado: EstadoReserva;
  estado_etiqueta: string;
  origen: string;
  /**
   * Montos en CENTIMOS, como los devuelve la API. La division por 100 para
   * mostrar pesos se hace una vez, en la pantalla, nunca en el dato.
   */
  total: number;
  pagado: number;
  saldo: number;
  estado_pago: EstadoPago;
  estado_pago_etiqueta: string;
  /** La bandera del problema clasico: pagado a medias. */
  pago_incompleto: boolean;
  pasajeros: PasajeroEnReserva[];
  emitida_en: string | null;
  cancelada_en: string | null;
  motivo_cancelacion: string | null;
  costo_cancelacion: number;
  creado_en: string;
}

export interface Asiento {
  id: string;
  numero: string;
  clase: string | null;
  etiqueta: string;
  estado: EstadoAsiento;
  estado_etiqueta: string;
  disponible: boolean;
  ocupado: boolean;
}

export interface EstadoDeCuenta {
  total: number;
  pagado: number;
  saldo: number;
  estado: EstadoPago;
  estado_etiqueta: string;
}

/** Convierte centimos a pesos Chilean. La division se hace con enteros. */
export function aPesos(centimos: number | null | undefined): string {
  const valor = Math.floor((centimos ?? 0) / 100);

  return '$' + valor.toLocaleString('es-CL');
}