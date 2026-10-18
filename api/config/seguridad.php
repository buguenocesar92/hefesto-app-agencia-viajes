<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Datos personales y seguridad
    |--------------------------------------------------------------------------
    |
    | Este archivo agrupa lo que la Ley 19.628 obliga a tener separado: el
    | documento de identidad de un pasajero es el dato mas sensible que maneja
    | esta aplicacion. Ver docs/07.
    */

    'datos_personales' => [

        /*
        | Caracteres del documento que quedan visibles en un listado.
        |
        | El numero COMPLETO nunca se devuelve en un listado: se devuelve
        | enmascarado (`AB1234***`). Para ver el numero entero hay que pedirlo
        | en un endpoint explicito, con permiso propio, y eso queda en la
        | bitacora. El listado es el lugar por donde se escapan los datos,
        | porque lo ve cualquiera que tenga acceso a la pantalla.
        */
        'caracteres_visibles' => 3,

        /*
        | Endpoint que entrega el documento completo. Cada peticion a el se
        | registra en `bitacora_accesos` con el agente, la hora y el motivo.
        */
        'exigir_motivo_para_leer_documento' => true,

    ],

    'auditoria' => [

        /*
        | Acciones que se anotan en la bitacora. La lectura del documento
        | completo esta porque consultar la identidad de un pasajero es, en
        | si mismo, un acceso a dato personal.
        */
        'acciones' => [
            'documento.leido',
            'documento.masked_leido',
            'reserva.creada',
            'reserva.estado_cambiado',
            'reserva.cancelada',
            'asiento.bloqueado',
            'asiento.liberado',
            'pago.registrado',
            'pasajero.creado',
        ],

    ],

    /*
    | Cuantos intentos de autenticacion se permiten antes de bloquear.
    */
    'max_intentos_login' => 5,

];