<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de alerta del sistema. Cada uno tiene su permiso (notificaciones.*): el ROL habilita
 * recibirla; la preferencia del usuario (PreferenciaNotificacion, "Mis notificaciones") la
 * silencia. Ver NotificacionService.
 */
enum TipoNotificacion: string
{
    case STOCK_BAJO = 'stock_bajo';
    case NCF_AGOTANDOSE = 'ncf_agotandose';
    case ECF_RECHAZADO = 'ecf_rechazado';
    case CXC_VENCIDAS = 'cxc_vencidas';
    case CXP_VENCIDAS = 'cxp_vencidas';

    public function permiso(): string
    {
        return "notificaciones.{$this->value}";
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::STOCK_BAJO => 'Stock bajo del mínimo',
            self::NCF_AGOTANDOSE => 'Secuencia NCF por agotarse',
            self::ECF_RECHAZADO => 'e-CF rechazado o sin respuesta de la DGII',
            self::CXC_VENCIDAS => 'Cuentas por cobrar vencidas',
            self::CXP_VENCIDAS => 'Cuentas por pagar vencidas',
        };
    }

    /**
     * true si además de la alerta del dashboard se envía como notificación in-app (la campana
     * de Filament) — solo esas se pueden silenciar en "Mis notificaciones". Las vencidas son un
     * estado que se consulta, no un evento: viven solo en el dashboard.
     */
    public function seEnviaEnApp(): bool
    {
        return match ($this) {
            self::STOCK_BAJO, self::NCF_AGOTANDOSE, self::ECF_RECHAZADO => true,
            self::CXC_VENCIDAS, self::CXP_VENCIDAS => false,
        };
    }

    /** @return array<string, string> permiso => etiqueta, para Permisos::catalogo() */
    public static function catalogoPermisos(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $tipo) => [$tipo->permiso() => "Recibir alertas: {$tipo->etiqueta()}"])
            ->all();
    }
}
