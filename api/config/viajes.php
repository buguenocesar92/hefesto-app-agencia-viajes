<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reglas de negocio de viajes
    |--------------------------------------------------------------------------
    |
    | LO QUE HAY EN ESTE ARCHIVO Y LO QUE NO
    | --------------------------------------
    | Aqui NO hay ningun plazo de cancelacion, ni ninguna regla de transformacion
    | de nombres. No porque esten en otro lado, sino porque NO SON DELIVABLES
    | POR PARTE DEL CODIGO: son acuerdos comerciales de cada agencia.
    |
    | Lo que si hay son los interruptores que hacen que el sistema **falle
    | cerrado** cuando esas reglas no estan configuradas:
    |
    |  - Si la agencia no tiene politica de cancelacion para un producto, la
    |    cancelacion se rechaza. El sistema no supone "sin costo".
    |  - Si la agencia no tiene politica de nombres, el boleto se arma con los
    |    datos tal cual estan en el documento, sin transformarlos, y queda
    |    marcado como NO VERIFICADO: no se puede emitir hasta que un agente lo
    |    revise.
    |
    | Los valores por defecto de abajo NO son reglas de negocio: son el
    | comportamiento prudente cuando no hay configuracion.
    */

    'asientos' => [

        /*
        | Estados que ocupan el asiento y por lo tanto competed entre si.
        | La restriccion de exclusion de PostgreSQL se aplica SOLO a estos
        | estados: un asiento liberado vuelve a estar disponible.
        */
        'estados_ocupantes' => ['bloqueado', 'asignado'],

        /*
        | Segundos que un bloqueo provisional se mantiene sin que alguien lo
        | renueve. Al vencer, el bloqueo se libera solo.
        |
        | ESTO NO ES LA GARANTIA DE QUE UN ASIENTO NO SE VENDA DOS VECES.
        | La garantia es el indice de exclusion de PostgreSQL. Este TTL solo
        | evita que un bloqueo olvidado apriete un asiento para siempre.
        */
        'candado_ttl_segundos' => (int) env('ASIENTOS_CANDADO_TTL', 15),

    ],

    'nombres' => [

        /*
        | Separador entre nombres y apellidos en el boleto.
        | No es una regla de aerolinea: cada agencia y cada aerolinea tiene la
        | suya. Se configura por agencia en la tabla `politicas_nombres`.
        */
        'separador_por_defecto' => ' ',

        /*
        | Si el sistema normaliza mayusculas y minusculas al comparar el
        | nombre del boleto contra el documento.
        |
        | ATENCION: normalizar para COMPARAR no es transformar el dato. El
        | boleto siempre lleva el texto del documento. Esto solo evita que
        | "PÉREZ" y "Pérez" se consideren distintos en la revision.
        */
        'comparar_sin_mayusculas' => true,

        /*
        | Cuantos caracteres del documento se muestran en los listados.
        | El numero completo nunca sale en un listado: sale enmascarado. Ver
        | docs/07.
        */
        'mascara_visibles' => 3,

    ],

    'cancelacion' => [

        /*
        | Cuando no hay politica de cancelacion configurada para el producto,
        | la cancelacion se RECHAZA en vez de asumir costo cero.
        | Escribir `true` aqui seria inventar un plazo de la agencia.
        */
        'permitir_sin_politica' => false,

    ],

];