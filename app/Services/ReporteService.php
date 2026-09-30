<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoCompra;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Enums\TipoDocumentoCliente;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Agregaciones para el dashboard y los reportes de gestión. Todos los métodos que miden
 * ingresos excluyen ventas con estado ANULADA: una anulación revierte el efecto fiscal y
 * contable de la venta, así que no debe contar como ingreso.
 */
class ReporteService
{
    /**
     * Código DGII de "Tipo de Ingreso" para el 607 (01 = Ingresos por operaciones, no
     * financieros). Este ERP no distingue tipos de ingreso (financieros, extraordinarios,
     * arrendamientos, etc.): todas las ventas son ingresos operacionales, así que se fija
     * en 01 para todas las filas. Centralizado aquí para ajustarlo fácilmente si en el
     * futuro se necesita derivarlo de otra fuente (p. ej. una configuración).
     */
    public const TIPO_INGRESO_DEFECTO = '01';

    /**
     * Base de los agregados de ingresos: ventas no anuladas, sin las Notas de Crédito de
     * anulación (venta_modificada_id) — la venta que anulan ya queda fuera por ANULADA, así que
     * contar la nota restaría (o sumaría) dos veces.
     */
    protected function ventasEmitidasEnRango(Carbon $desde, Carbon $hasta): Builder
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('estado', '!=', EstadoVenta::ANULADA)
            ->whereNull('venta_modificada_id');
    }

    /**
     * Todas las ventas del rango (incluye ANULADAs) para el listado del reporte de ventas:
     * a diferencia de los agregados de ingresos, aquí interesa la trazabilidad completa.
     *
     * $empresaId: solo lo necesitan los controllers de PDF (rutas fuera del panel de Filament,
     * donde el scoping automático por tenant no aplica). Los widgets del dashboard, que corren
     * dentro del panel, no lo pasan porque ya llegan scopeados por el global scope de Filament.
     */
    public function ventasEnRangoQuery(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Builder
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('empresa_id', $id));
    }

    /**
     * Base del Formato 607 (Envío de Ventas de Bienes y Servicios) de la DGII: un e-NCF por
     * fila. Regla fiscal (confirmada en la Norma General 07-18 y en la comunidad de ayuda de
     * la DGII): las ventas ANULADAS NO se reportan en el 607 —se excluyen por completo, no
     * solo de los montos— porque un NCF anulado se declara aparte, en el Formato 608 (Ventas
     * Anuladas), junto con el motivo de anulación. Reportarlo también en el 607 duplicaría
     * la operación ante la DGII. Solo se incluyen comprobantes con e-NCF asignado, ya que el
     * 607 es un reporte de comprobantes fiscales emitidos.
     *
     * Excepción: un e-CF que la DGII ya aceptó y se anuló con Nota de Crédito (e-CF 34) SÍ va
     * al 607 aunque esté ANULADA — para la DGII sigue siendo válido; la Nota de Crédito (que
     * también va, con su NCF modificado) es la que lo contrarresta. Solo lo que nunca llegó a
     * ser válido (físicos, e-CF rechazados) es "anulado" en el sentido del 608.
     */
    public function reporte607Query(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Builder
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where(fn (Builder $query) => $query
                ->where('estado', '!=', EstadoVenta::ANULADA)
                ->orWhereHas('notasCredito'))
            ->whereNotNull('ncf')
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('empresa_id', $id))
            ->with('cliente');
    }

    /**
     * Una fila por e-NCF emitido en el rango, con el mapeo de columnas del 607.
     *
     * Notas de mapeo (verificadas contra el instructivo oficial de la DGII y su comunidad de
     * ayuda, no solo inferidas):
     * - tipo_identificacion: 1=RNC, 2=Cédula (códigos oficiales del 607). El código oficial 3
     *   es "Pasaporte", que este sistema no captura; para clientes sin documento (consumo por
     *   debajo del umbral de RD$250,000, donde la DGII indica NO solicitar identificación) se
     *   deja en null —el campo va en blanco en el 607 real, no con un código inventado—.
     * - monto_facturado: es la base ANTES de ITBIS y DESPUÉS del descuento (Venta::subtotalNeto()),
     *   no el total. La DGII
     *   documenta "Monto Facturado" como el subtotal sin impuestos; el ITBIS va aparte en
     *   itbis_facturado.
     *
     * @return Collection<int, array{
     *   rnc_cedula: ?string,
     *   tipo_identificacion: ?int,
     *   numero_comprobante: string,
     *   numero_comprobante_modificado: ?string,
     *   tipo_ingreso: string,
     *   fecha_comprobante: Carbon,
     *   monto_facturado: string,
     *   itbis_facturado: string,
     *   monto_total: string,
     * }>
     */
    public function reporte607(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Collection
    {
        return $this->reporte607Query($desde, $hasta, $empresaId)
            ->orderBy('fecha')
            ->get()
            ->map(fn (Venta $venta) => [
                'rnc_cedula' => $this->rncCedula607($venta->cliente?->tipo_documento, $venta->cliente?->documento),
                'tipo_identificacion' => $this->tipoIdentificacion607($venta->cliente?->tipo_documento),
                'numero_comprobante' => $venta->ncf,
                'numero_comprobante_modificado' => $venta->ncf_modifica,
                'tipo_ingreso' => self::TIPO_INGRESO_DEFECTO,
                'fecha_comprobante' => $venta->fecha,
                'monto_facturado' => $venta->subtotalNeto(),
                'itbis_facturado' => (string) $venta->total_itbis,
                'monto_total' => (string) $venta->total,
            ]);
    }

    /**
     * RNC/Cédula del comprador para el 607, según el tipo de documento del cliente. Único
     * punto donde vive esta regla —usado tanto por reporte607() como por la página y los
     * exportadores del 607— para que el mapeo no se desalinee entre pantalla y archivo.
     */
    public function rncCedula607(?TipoDocumentoCliente $tipoDocumento, ?string $documento): ?string
    {
        // Null = venta al portador: igual que un cliente sin documento, el campo va en blanco.
        return match ($tipoDocumento) {
            null => null,
            TipoDocumentoCliente::RNC, TipoDocumentoCliente::CEDULA => $documento,
            TipoDocumentoCliente::SIN_DOCUMENTO => null,
        };
    }

    /**
     * Código DGII de "Tipo de Identificación" para el 607: 1=RNC, 2=Cédula. Ver la nota en
     * reporte607() sobre por qué "sin documento" es null y no un código 3 inventado.
     */
    public function tipoIdentificacion607(?TipoDocumentoCliente $tipoDocumento): ?int
    {
        return match ($tipoDocumento) {
            null => null,
            TipoDocumentoCliente::RNC => 1,
            TipoDocumentoCliente::CEDULA => 2,
            TipoDocumentoCliente::SIN_DOCUMENTO => null,
        };
    }

    /**
     * Etiqueta legible del código de tipo_identificacion607(), para pantalla/PDF/exportables.
     */
    public function etiquetaTipoIdentificacion607(?int $codigo): string
    {
        return match ($codigo) {
            1 => 'RNC',
            2 => 'Cédula',
            default => '—',
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Formato 606 — Envío de Compras de Bienes y Servicios
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Catálogo DGII de "Tipo de bienes y servicios" para el 606.
     */
    public const TIPO_BIENES_SERVICIOS_606 = [
        '01' => 'Gastos de personal',
        '02' => 'Gastos por trabajos, suministros y servicios',
        '03' => 'Arrendamientos',
        '04' => 'Gastos de activos fijos',
        '05' => 'Gastos de representación',
        '06' => 'Otras deducciones admitidas',
        '07' => 'Gastos financieros',
        '08' => 'Gastos extraordinarios',
        '09' => 'Compras y gastos que formarán parte del costo de venta',
        '10' => 'Adquisiciones de activos',
        '11' => 'Gastos de seguros',
    ];

    /**
     * Catálogo DGII de "Forma de pago" para el 606 (y 608).
     */
    public const FORMA_PAGO_606 = [
        '01' => 'Efectivo',
        '02' => 'Cheque / Transferencia / Depósito',
        '03' => 'Tarjeta de crédito / débito',
        '04' => 'Compra a crédito',
        '05' => 'Permuta',
        '06' => 'Nota de crédito',
        '07' => 'Mixto',
    ];

    /**
     * Query base del 606: compras no anuladas con NCF de proveedor en el rango.
     * Solo compras con NCF entran al 606 — las informales (sin NCF) se excluyen.
     */
    public function reporte606Query(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Builder
    {
        return Compra::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('estado', '!=', EstadoCompra::ANULADA)
            ->whereNotNull('ncf')
            ->where('ncf', '!=', '')
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('empresa_id', $id))
            ->with('proveedor');
    }

    /**
     * Una fila por compra con NCF en el rango, con el mapeo de columnas del 606.
     *
     * @return Collection<int, array{
     *   rnc_cedula: ?string,
     *   tipo_identificacion: ?int,
     *   tipo_bienes_servicios: string,
     *   ncf: string,
     *   ncf_modificado: string,
     *   fecha_comprobante: Carbon,
     *   fecha_pago: ?string,
     *   monto_servicios: string,
     *   monto_bienes: string,
     *   total_facturado: string,
     *   itbis_retenido: string,
     *   itbis_facturado: string,
     *   forma_pago: string,
     * }>
     */
    public function reporte606(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Collection
    {
        return $this->reporte606Query($desde, $hasta, $empresaId)
            ->orderBy('fecha')
            ->get()
            ->map(fn (Compra $compra) => [
                'rnc_cedula' => $compra->proveedor?->rnc,
                'tipo_identificacion' => $this->tipoIdentificacion606($compra->proveedor?->rnc),
                'tipo_bienes_servicios' => $compra->tipo_bienes_servicios_606 ?? '09',
                'ncf' => $compra->ncf,
                'ncf_modificado' => '',
                'fecha_comprobante' => $compra->fecha,
                'fecha_pago' => $compra->fecha_pago?->format('Ymd'),
                'monto_servicios' => '0.00',
                'monto_bienes' => number_format((float) $compra->subtotal, 2, '.', ''),
                'total_facturado' => number_format((float) $compra->total, 2, '.', ''),
                'itbis_retenido' => number_format((float) ($compra->retencion_itbis ?? 0), 2, '.', ''),
                'itbis_facturado' => number_format((float) $compra->itbis, 2, '.', ''),
                'itbis_proporcionalidad' => '0.00',
                'itbis_costo' => '0.00',
                'itbis_adelantado' => '0.00',
                'itbis_percibido' => '0.00',
                'tipo_retencion_isr' => $compra->tipo_retencion_isr ?? '',
                'monto_retencion_renta' => number_format((float) ($compra->retencion_isr ?? 0), 2, '.', ''),
                'isr_percibido' => '0.00',
                'impuesto_selectivo' => '0.00',
                'otros_impuestos' => '0.00',
                'monto_propina' => '0.00',
                'forma_pago' => $compra->forma_pago_606 ?? '01',
            ]);
    }

    /**
     * Tipo de identificación del proveedor para el 606: 1=RNC (9 dígitos), 2=Cédula (11 dígitos).
     */
    public function tipoIdentificacion606(?string $rnc): ?int
    {
        if ($rnc === null || $rnc === '') {
            return null;
        }

        $limpio = preg_replace('/\D/', '', $rnc);

        return strlen($limpio) === 9 ? 1 : 2;
    }

    /**
     * Etiqueta legible del tipo de identificación del proveedor.
     */
    public function etiquetaTipoIdentificacion606(?int $codigo): string
    {
        return match ($codigo) {
            1 => 'RNC',
            2 => 'Cédula',
            default => '—',
        };
    }

    /**
     * Generar el TXT del 606 en formato DGII (pipe-delimited).
     */
    public function exportar606Txt(string $rncEmpresa, Carbon $desde, Carbon $hasta, ?int $empresaId = null): string
    {
        $registros = $this->reporte606($desde, $hasta, $empresaId);
        $periodo = $desde->format('Ym');

        $lineas = [];

        // Encabezado
        $lineas[] = implode('|', ['606', $rncEmpresa, $periodo, (string) $registros->count()]);

        // Registros
        foreach ($registros as $reg) {
            $lineas[] = implode('|', [
                $reg['rnc_cedula'] ?? '',
                (string) ($reg['tipo_identificacion'] ?? ''),
                $reg['tipo_bienes_servicios'],
                $reg['ncf'],
                $reg['ncf_modificado'],
                $reg['fecha_comprobante']->format('Ymd'),
                $reg['fecha_pago'] ?? '',
                $reg['monto_servicios'],
                $reg['monto_bienes'],
                $reg['total_facturado'],
                $reg['itbis_retenido'],
                $reg['itbis_facturado'],
                $reg['itbis_proporcionalidad'],
                $reg['itbis_costo'],
                $reg['itbis_adelantado'],
                $reg['itbis_percibido'],
                $reg['tipo_retencion_isr'],
                $reg['monto_retencion_renta'],
                $reg['isr_percibido'],
                $reg['impuesto_selectivo'],
                $reg['otros_impuestos'],
                $reg['monto_propina'],
                $reg['forma_pago'],
            ]);
        }

        return implode("\n", $lineas);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Formato 608 — Comprobantes Fiscales Anulados
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Catálogo DGII de "Tipo de anulación" para el 608.
     */
    public const TIPO_ANULACION_608 = [
        '01' => 'Deterioro de factura pre-impresa',
        '02' => 'Errores de impresión (factura pre-impresa)',
        '03' => 'Impresión defectuosa',
        '04' => 'Corrección de la información',
        '05' => 'Cambio de productos',
        '06' => 'Devolución de productos',
        '07' => 'Omisión de productos',
        '08' => 'Errores en secuencia de NCF',
        '09' => 'Por cese de operaciones',
        '10' => 'Pérdida o hurto de talonarios',
    ];

    /**
     * Query base del 608: ventas ANULADAS con comprobante FÍSICO (tipo B) y NCF, en el rango
     * por fecha de anulación (anulada_en), no por fecha de emisión.
     *
     * Los e-CF anulados NO van al 608: su anulación se reporta vía Nota de Crédito E34
     * directamente ante la DGII.
     */
    public function reporte608Query(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Builder
    {
        $tiposFisicos = collect(TipoComprobante::cases())
            ->filter->esFisico()
            ->map->value
            ->all();

        return Venta::query()
            ->whereBetween('anulada_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('estado', EstadoVenta::ANULADA)
            ->whereNotNull('ncf')
            ->where('ncf', '!=', '')
            ->whereIn('tipo_comprobante', $tiposFisicos)
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('empresa_id', $id));
    }

    /**
     * Una fila por comprobante físico anulado en el rango, con las 3 columnas del 608.
     *
     * @return Collection<int, array{ncf: string, tipo_anulacion: string, fecha_anulacion: string}>
     */
    public function reporte608(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Collection
    {
        return $this->reporte608Query($desde, $hasta, $empresaId)
            ->orderBy('anulada_en')
            ->get()
            ->map(fn (Venta $venta) => [
                'ncf' => $venta->ncf,
                'tipo_anulacion' => $venta->tipo_anulacion_608 ?? '04',
                'fecha_anulacion' => $venta->anulada_en->format('Ymd'),
            ]);
    }

    /**
     * Generar el TXT del 608 en formato DGII (pipe-delimited).
     */
    public function exportar608Txt(string $rncEmpresa, Carbon $desde, Carbon $hasta, ?int $empresaId = null): string
    {
        $registros = $this->reporte608($desde, $hasta, $empresaId);
        $periodo = $desde->format('Ym');

        $lineas = [];

        // Encabezado
        $lineas[] = implode('|', ['608', $rncEmpresa, $periodo, (string) $registros->count()]);

        // Registros
        foreach ($registros as $reg) {
            $lineas[] = implode('|', [
                $reg['ncf'],
                $reg['tipo_anulacion'],
                $reg['fecha_anulacion'],
            ]);
        }

        return implode("\n", $lineas);
    }

    /**
     * @return array{total_vendido: string, total_itbis: string, cantidad_ventas: int, ticket_promedio: string}
     */
    public function ventasPorRango(Carbon $desde, Carbon $hasta): array
    {
        $fila = $this->ventasEmitidasEnRango($desde, $hasta)
            ->selectRaw('COALESCE(SUM(total), 0) as total_vendido')
            ->selectRaw('COALESCE(SUM(total_itbis), 0) as total_itbis')
            ->selectRaw('COUNT(*) as cantidad_ventas')
            ->first();

        $totalVendido = (string) $fila->total_vendido;
        $cantidadVentas = (int) $fila->cantidad_ventas;

        return [
            'total_vendido' => $totalVendido,
            'total_itbis' => (string) $fila->total_itbis,
            'cantidad_ventas' => $cantidadVentas,
            'ticket_promedio' => $cantidadVentas > 0
                ? bcdiv($totalVendido, (string) $cantidadVentas, 2)
                : '0.00',
        ];
    }

    /**
     * Desglose de comprobantes emitidos en el rango: cuántos son electrónicos (e-CF, pasaron
     * por el PAC) vs. físicos (tipo B, nunca se transmiten). Para el widget del dashboard.
     *
     * @return array{electronicos: int, fisicos: int}
     */
    public function desgloseComprobantes(Carbon $desde, Carbon $hasta): array
    {
        $tiposElectronicos = collect(TipoComprobante::cases())->filter->esElectronico()->map->value->all();
        $tiposFisicos = collect(TipoComprobante::cases())->filter->esFisico()->map->value->all();

        $base = $this->ventasEmitidasEnRango($desde, $hasta)->whereNotNull('ncf');

        return [
            'electronicos' => (clone $base)->whereIn('tipo_comprobante', $tiposElectronicos)->count(),
            'fisicos' => (clone $base)->whereIn('tipo_comprobante', $tiposFisicos)->count(),
        ];
    }

    /**
     * Serie para gráfico: fecha (Y-m-d) => total vendido ese día.
     *
     * @return Collection<string, string>
     */
    public function ventasPorDia(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->selectRaw('DATE(fecha) as dia')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->mapWithKeys(fn ($fila) => [(string) $fila->dia => (string) $fila->total]);
    }

    public function productosVendidosQuery(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Builder
    {
        return Producto::query()
            ->join('detalle_ventas', 'detalle_ventas.producto_id', '=', 'productos.id')
            ->join('ventas', 'ventas.id', '=', 'detalle_ventas.venta_id')
            ->whereBetween('ventas.fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('ventas.estado', '!=', EstadoVenta::ANULADA)
            ->whereNull('ventas.venta_modificada_id')
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('ventas.empresa_id', $id))
            ->groupBy('productos.id', 'productos.codigo', 'productos.nombre')
            ->selectRaw('productos.id, productos.codigo, productos.nombre')
            ->selectRaw('COALESCE(SUM(detalle_ventas.cantidad), 0) as cantidad_vendida')
            ->selectRaw('COALESCE(SUM(detalle_ventas.subtotal), 0) as ingresos');
    }

    /**
     * @return array{por_cantidad: Collection, por_ingresos: Collection}
     */
    public function topProductos(Carbon $desde, Carbon $hasta, int $limite = 10): array
    {
        $base = $this->productosVendidosQuery($desde, $hasta);

        return [
            'por_cantidad' => (clone $base)->orderByDesc('cantidad_vendida')->limit($limite)->get(),
            'por_ingresos' => (clone $base)->orderByDesc('ingresos')->limit($limite)->get(),
        ];
    }

    public function ventasPorClienteQuery(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Builder
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('ventas.empresa_id', $id))
            // leftJoin: las ventas al portador (cliente_id null) se agrupan en una sola fila.
            ->leftJoin('clientes', 'clientes.id', '=', 'ventas.cliente_id')
            ->groupBy('clientes.id', 'clientes.nombre')
            // "id" además de "cliente_id": el modelo base de la consulta sigue siendo Venta,
            // y Filament identifica cada fila de tabla con getKey() (columna "id"); la fila al
            // portador usa 0 porque no tiene cliente.
            ->selectRaw('COALESCE(clientes.id, 0) as id, clientes.id as cliente_id')
            ->selectRaw('COALESCE(clientes.nombre, ?) as cliente_nombre', [Venta::ETIQUETA_AL_PORTADOR])
            ->selectRaw('COALESCE(SUM(ventas.total), 0) as total_vendido')
            ->selectRaw('COUNT(*) as cantidad_ventas');
    }

    public function ventasPorCliente(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasPorClienteQuery($desde, $hasta)->orderByDesc('total_vendido')->get();
    }

    public function ventasPorUsuarioQuery(Carbon $desde, Carbon $hasta, ?int $empresaId = null): Builder
    {
        return $this->ventasEmitidasEnRango($desde, $hasta)
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('ventas.empresa_id', $id))
            ->join('users', 'users.id', '=', 'ventas.user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.id as id, users.id as user_id, users.name as user_nombre')
            ->selectRaw('COALESCE(SUM(ventas.total), 0) as total_vendido')
            ->selectRaw('COUNT(*) as cantidad_ventas');
    }

    public function ventasPorUsuario(Carbon $desde, Carbon $hasta): Collection
    {
        return $this->ventasPorUsuarioQuery($desde, $hasta)->orderByDesc('total_vendido')->get();
    }

    /**
     * Conteo por estado_fiscal, separado también por estado (emitida/anulada): incluye
     * anuladas porque el propósito aquí es monitoreo del ciclo fiscal DGII, no ingresos.
     */
    public function ventasPorEstadoFiscal(Carbon $desde, Carbon $hasta): Collection
    {
        return Venta::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->groupBy('estado', 'estado_fiscal')
            ->selectRaw('estado, estado_fiscal, COUNT(*) as cantidad')
            ->orderBy('estado_fiscal')
            ->get()
            ->map(fn ($fila) => [
                'estado' => $fila->estado,
                'estado_fiscal' => $fila->estado_fiscal,
                'cantidad' => (int) $fila->cantidad,
            ]);
    }

    public function valorInventario(?int $empresaId = null): string
    {
        return (string) Producto::query()
            ->where('controla_stock', true)
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('empresa_id', $id))
            ->selectRaw('COALESCE(SUM(costo * stock), 0) as valor')
            ->value('valor');
    }

    public function productosBajoMinimoQuery(?int $empresaId = null): Builder
    {
        return Producto::query()
            ->activos()
            ->bajoMinimo()
            ->when($empresaId, fn (Builder $query, int $id) => $query->where('empresa_id', $id));
    }

    /**
     * @return Collection<int, Producto>
     */
    public function productosBajoMinimo(): Collection
    {
        return $this->productosBajoMinimoQuery()->orderBy('nombre')->get();
    }
}
