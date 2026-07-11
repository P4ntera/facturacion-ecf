<?php

namespace App\Enums;

/**
 * Códigos de evento que trae el historial ("eventos") dentro de la respuesta cruda del PAC
 * (Venta::ecf_respuesta). Solo traduce a español los conocidos; un código nuevo del PAC se
 * muestra tal cual (ver VentaResource).
 */
enum EventoEcf: string
{
    case AUTENTICACION_EXITOSA = 'AUTENTICACION_EXITOSA';
    case DOCUMENTO_FIRMADO = 'DOCUMENTO_FIRMADO';
    case RESPUESTA_DGII = 'RESPUESTA_DGII';
    case TRACK_STATUS = 'TRACK_STATUS';

    public function etiqueta(): string
    {
        return match ($this) {
            self::AUTENTICACION_EXITOSA => 'Autenticación exitosa ante el PAC',
            self::DOCUMENTO_FIRMADO => 'Documento firmado digitalmente',
            self::RESPUESTA_DGII => 'Respuesta de la DGII',
            self::TRACK_STATUS => 'Consulta de estado (track)',
        };
    }
}
