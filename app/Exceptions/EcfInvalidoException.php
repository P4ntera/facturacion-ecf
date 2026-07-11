<?php

namespace App\Exceptions;

/**
 * La Venta no tiene los datos mínimos para construir un e-CF válido (p. ej. falta el RNC del
 * comprador en una Factura de Crédito Fiscal). A diferencia de un error de red del PAC, esto es
 * un problema de datos: reintentar el envío no lo arregla.
 */
class EcfInvalidoException extends \Exception {}
