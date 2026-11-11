<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Enums\AmbienteEcf;
use App\Enums\Modulo;
use App\Enums\PerfilEmpresa;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Services\ModulosEmpresaService;
use App\Services\RolesEmpresaService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Base común de los seeders demo (lavandería, heladería, veterinaria).
 *
 * Cada vertical se siembra en TRES empresas, una por escenario fiscal, para probar todas las
 * combinaciones de configuración que el sistema soporta:
 *
 *  - INFORMAL: sin RNC, no cobra ITBIS, solo vende "sin comprobante" (colmado, negocio pequeño).
 *  - TIPO_B:   con RNC, cobra ITBIS ya incluido en el precio, NCF físicos B01/B02/B04 y también
 *              permite cobro rápido sin comprobante.
 *  - ECF:      con RNC, emisor electrónico, ITBIS aparte del precio, e-CF E31/E32/E33/E34 y NO
 *              permite vender sin comprobante (toda venta lleva e-NCF).
 *
 * Todo es idempotente (firstOrCreate por clave natural): correr el seeder dos veces no duplica
 * empresas, usuarios ni catálogos. Las transacciones demo (ventas, tickets) solo se crean la
 * primera vez, cuando la empresa no tiene ventas.
 */
trait SiembraEmpresaDemo
{
    public const ESCENARIO_INFORMAL = 'informal';

    public const ESCENARIO_TIPO_B = 'tipo_b';

    public const ESCENARIO_ECF = 'ecf';

    /** Contraseña de todos los usuarios demo. */
    protected const PASSWORD_DEMO = 'password123';

    /** Nunca sembrar datos demo en producción: crearía empresas y ventas ficticias reales. */
    protected function abortarEnProduccion(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Los seeders demo no se ejecutan en producción.');
        }
    }

    /**
     * Crea (o reutiliza) la empresa de un escenario con su configuración fiscal, roles, módulos,
     * caja, secuencias y usuario administrador.
     *
     * @param  array{razon_social: string, nombre_comercial: string, rnc: ?string, slug: string, email_admin: string, direccion?: string, telefono?: string}  $datos
     * @param  array<int, Modulo>  $modulosExtra  módulos a habilitar además de los del perfil
     * @return array{0: Empresa, 1: User}
     */
    protected function crearEmpresaDemo(PerfilEmpresa $perfil, string $escenario, array $datos, array $modulosExtra = []): array
    {
        $empresa = Empresa::firstOrCreate(
            ['slug' => $datos['slug']],
            [
                'rnc' => $datos['rnc'],
                'razon_social' => $datos['razon_social'],
                'nombre_comercial' => $datos['nombre_comercial'],
                'direccion' => $datos['direccion'] ?? 'Calle Principal #1, Santiago, R.D.',
                'telefono' => $datos['telefono'] ?? '809-555-0000',
                'perfil' => $perfil,
                'usa_ecf' => $escenario === self::ESCENARIO_ECF,
                'activa' => true,
            ],
        );

        $empresa->config()->update($this->configuracionFiscal($escenario));

        app(RolesEmpresaService::class)->sembrarRolesBase($empresa);
        app(ModulosEmpresaService::class)->sembrarModulos($empresa);

        foreach ($modulosExtra as $modulo) {
            EmpresaModulo::updateOrCreate(
                ['empresa_id' => $empresa->id, 'modulo' => $modulo],
                ['habilitado' => true],
            );
        }

        Caja::firstOrCreate(
            ['empresa_id' => $empresa->id, 'codigo' => 'CAJA-01'],
            ['nombre' => 'Caja principal', 'activo' => true],
        );

        $this->sembrarSecuencias($empresa, $escenario);

        $admin = $this->crearUsuarioDemo($empresa, 'Admin '.$datos['nombre_comercial'], $datos['email_admin'], 'Administrador');

        return [$empresa->fresh(), $admin];
    }

    /** @return array<string, mixed> */
    protected function configuracionFiscal(string $escenario): array
    {
        return match ($escenario) {
            self::ESCENARIO_INFORMAL => [
                'aplica_itbis' => false,
                'precio_incluye_itbis' => false,
                'permite_ventas_sin_comprobante' => true,
                'tipo_comprobante_defecto' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            ],
            self::ESCENARIO_TIPO_B => [
                'aplica_itbis' => true,
                'precio_incluye_itbis' => true,
                'permite_ventas_sin_comprobante' => true,
                'tipo_comprobante_defecto' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            ],
            self::ESCENARIO_ECF => [
                'aplica_itbis' => true,
                'precio_incluye_itbis' => false,
                'permite_ventas_sin_comprobante' => false,
                'tipo_comprobante_defecto' => TipoComprobante::FACTURA_CONSUMO->value,
                'dgii_ambiente' => AmbienteEcf::TESTECF,
                // Valores de relleno para que el envío no se rechace por configuración faltante.
                // En local el envío usa el gateway simulado; para probar contra el PAC real,
                // reemplazar ambos en Ajustes de la Empresa y activar DGII_FORZAR_GATEWAY_REAL.
                'dgii_base_url' => 'https://pac-demo.invalid/api/v1',
                'dgii_api_key' => 'reemplazar-por-api-key-del-pac',
            ],
        };
    }

    /**
     * Secuencias por escenario. Prefijo = serie + tipo (E31, B02...): SecuenciaNcfService le
     * agrega el secuencial (10 dígitos en e-CF, 8 en físicos) para formar un NCF de 13 / 11.
     */
    protected function sembrarSecuencias(Empresa $empresa, string $escenario): void
    {
        $tipos = match ($escenario) {
            self::ESCENARIO_INFORMAL => [],
            self::ESCENARIO_TIPO_B => [
                TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
                TipoComprobante::FACTURA_CONSUMO_FISICA,
                TipoComprobante::NOTA_CREDITO_FISICA,
            ],
            self::ESCENARIO_ECF => [
                TipoComprobante::FACTURA_CREDITO_FISCAL,
                TipoComprobante::FACTURA_CONSUMO,
                TipoComprobante::NOTA_DEBITO,
                TipoComprobante::NOTA_CREDITO,
            ],
        };

        foreach ($tipos as $tipo) {
            SecuenciaNcf::firstOrCreate(
                ['empresa_id' => $empresa->id, 'tipo_comprobante' => $tipo],
                [
                    'prefijo' => $tipo->esElectronico() ? 'E'.$tipo->value : $tipo->value,
                    'secuencia_desde' => 1,
                    'secuencia_actual' => 1,
                    'secuencia_hasta' => 5000,
                    'vencimiento' => now()->addYear()->endOfYear(),
                    'activa' => true,
                ],
            );
        }
    }

    protected function crearUsuarioDemo(Empresa $empresa, string $nombre, string $email, string $rol): User
    {
        $usuario = User::firstOrCreate(
            ['email' => $email],
            [
                'empresa_id' => $empresa->id,
                'name' => $nombre,
                'password' => bcrypt(self::PASSWORD_DEMO),
                'email_verified_at' => now(),
            ],
        );

        if ($rol === 'Administrador') {
            app(RolesEmpresaService::class)->asignarAdministrador($usuario, $empresa);
        } else {
            setPermissionsTeamId($empresa->id);

            if (! $usuario->hasRole($rol)) {
                $usuario->assignRole($rol);
            }
        }

        return $usuario;
    }

    /**
     * Clientes demo: uno con RNC (crédito fiscal E31/B01), uno con cédula y uno sin documento.
     *
     * @return array{rnc: Cliente, cedula: Cliente, sin_documento: Cliente}
     */
    protected function sembrarClientesDemo(Empresa $empresa, string $sufijoRnc): array // $sufijoRnc: 7 dígitos
    {
        $crear = fn (TipoDocumentoCliente $tipo, ?string $documento, string $nombre, array $extra = []) => Cliente::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => $nombre],
            ['tipo_documento' => $tipo, 'documento' => $documento, 'activo' => true] + $extra,
        );

        return [
            // RNC de 9 dígitos y cédula de 11, como los valida la DGII.
            'rnc' => $crear(TipoDocumentoCliente::RNC, '13'.$sufijoRnc, 'Distribuidora Cliente SRL', ['telefono' => '809-555-0101', 'email' => 'compras@distribuidora.test']),
            'cedula' => $crear(TipoDocumentoCliente::CEDULA, '001'.$sufijoRnc.'3', 'Ana García', ['telefono' => '809-555-0102']),
            'sin_documento' => $crear(TipoDocumentoCliente::SIN_DOCUMENTO, null, 'Cliente Frecuente (sin documento)'),
        ];
    }

    /** Línea de resumen de credenciales para la consola. */
    protected function resumenEmpresa(Empresa $empresa, string $escenario, array $emails): void
    {
        $etiqueta = match ($escenario) {
            self::ESCENARIO_INFORMAL => 'Informal: sin ITBIS, solo sin comprobante',
            self::ESCENARIO_TIPO_B => 'Tipo B: ITBIS incluido, B01/B02/B04 + sin comprobante',
            self::ESCENARIO_ECF => 'e-CF: ITBIS aparte, E31/E32/E33/E34, obligatorio comprobante',
        };

        $this->command->info("  ✓ {$empresa->nombre_comercial} — {$etiqueta}");

        foreach ($emails as $email) {
            $this->command->line("      {$email} / ".self::PASSWORD_DEMO);
        }
    }

    protected function slug(string $texto): string
    {
        return Str::slug($texto);
    }
}
