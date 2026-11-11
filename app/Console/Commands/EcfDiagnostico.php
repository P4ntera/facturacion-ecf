<?php

namespace App\Console\Commands;

use App\Exceptions\DgiiGatewayException;
use App\Models\Venta;
use App\Services\Dgii\DgiiGatewayFactory;
use App\Services\Dgii\EcfBuilder;
use Illuminate\Console\Command;
use Throwable;

class EcfDiagnostico extends Command
{
    protected $signature = 'ecf:diagnostico {venta : ID de la venta a diagnosticar}';

    protected $description = 'Imprime el JSON del e-CF, extrae etiquetas del XML firmado y muestra la respuesta de la DGII';

    private const ETIQUETAS_XML = [
        'RNCEmisor',
        'RazonSocialEmisor',
        'FechaEmision',
        'FechaVencimientoSecuencia',
        'IndicadorMontoGravado',
        'IndicadorServicioTodoIncluido',
        'TipoIngresos',
        'TipoPago',
        'FormaPago',
        'CodigoModificacion',
        'FechaHoraFirma',
    ];

    public function handle(EcfBuilder $builder, DgiiGatewayFactory $gatewayFactory): int
    {
        $venta = Venta::with(['detalles.producto', 'cliente', 'empresa'])->find($this->argument('venta'));

        if ($venta === null) {
            $this->error("No se encontró la venta #{$this->argument('venta')}.");

            return self::FAILURE;
        }

        $this->imprimirJson($builder, $venta);
        $this->imprimirXml($gatewayFactory, $venta);
        $this->imprimirRespuesta($venta);

        return self::SUCCESS;
    }

    private function imprimirJson(EcfBuilder $builder, Venta $venta): void
    {
        $this->info('── JSON que genera EcfBuilder::construir() ──');

        try {
            $ecf = $builder->construir($venta);
            $this->line(json_encode($ecf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            $this->error("EcfBuilder rechazó la venta: {$e->getMessage()}");
        }
    }

    private function imprimirXml(DgiiGatewayFactory $gatewayFactory, Venta $venta): void
    {
        $this->newLine();
        $this->info('── Etiquetas del XML firmado (PAC) ──');

        if ($venta->pac_id === null) {
            $this->warn('La venta no tiene pac_id; no se puede descargar el XML firmado.');

            return;
        }

        try {
            $gateway = $gatewayFactory->make($venta->empresa);
            $xml = $gateway->descargarXml($venta->pac_id);
        } catch (DgiiGatewayException $e) {
            $this->error("No se pudo descargar el XML: {$e->getMessage()}");

            return;
        }

        foreach (self::ETIQUETAS_XML as $etiqueta) {
            $this->reportarEtiqueta($xml, $etiqueta);
        }

        $this->reportarIndicadoresFacturacion($xml);
        $this->reportarSignature($xml);
    }

    private function reportarEtiqueta(string $xml, string $etiqueta): void
    {
        if (preg_match("/<{$etiqueta}>(.*?)<\/{$etiqueta}>/s", $xml, $m)) {
            $this->line("  {$etiqueta}: <comment>{$m[1]}</comment>");
        } else {
            $this->line("  {$etiqueta}: <fg=red>NO PRESENTE</>");
        }
    }

    private function reportarIndicadoresFacturacion(string $xml): void
    {
        if (preg_match_all('/<IndicadorFacturacion>(.*?)<\/IndicadorFacturacion>/s', $xml, $matches)) {
            foreach ($matches[1] as $i => $valor) {
                $linea = $i + 1;
                $this->line("  IndicadorFacturacion (línea {$linea}): <comment>{$valor}</comment>");
            }
        } else {
            $this->line('  IndicadorFacturacion: <fg=red>NO PRESENTE</>');
        }
    }

    private function reportarSignature(string $xml): void
    {
        if (preg_match('/<SignatureValue>(.*?)<\/SignatureValue>/s', $xml, $m)) {
            $this->line('  SignatureValue: <comment>'.substr($m[1], 0, 6).'...</comment>');
        } else {
            $this->line('  SignatureValue: <fg=red>NO PRESENTE</>');
        }
    }

    private function imprimirRespuesta(Venta $venta): void
    {
        $this->newLine();
        $this->info('── ecf_respuesta (mensajes de la DGII) ──');

        if (blank($venta->ecf_respuesta)) {
            $this->warn('La venta no tiene ecf_respuesta registrada.');

            return;
        }

        $this->line(json_encode($venta->ecf_respuesta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
