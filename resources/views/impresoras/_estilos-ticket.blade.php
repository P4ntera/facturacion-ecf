{{-- Reutilizado por el ticket de venta (resources/views/ventas/ticket.blade.php) y la vista de
     prueba de impresión: mismo criterio de ancho fijo/fuente monoespaciada para papel térmico. --}}
<style>
    @page {
        size: {{ $anchoPapel->value }}mm auto;
        margin: 0;
    }

    * {
        box-sizing: border-box;
    }

    body {
        width: {{ $anchoPapel->value }}mm;
        margin: 0;
        padding: 2mm;
        font-family: 'Courier New', Courier, monospace;
        font-size: 11px;
        line-height: 1.35;
        color: #000;
    }

    p {
        margin: 0 0 2px;
    }

    .centro {
        text-align: center;
    }

    .derecha {
        text-align: right;
    }

    .negrita {
        font-weight: bold;
    }

    .separador {
        border-top: 1px dashed #000;
        margin: 4px 0;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    td {
        padding: 0;
        vertical-align: top;
    }

    .col-cant {
        width: 14%;
    }

    .col-precio {
        width: 26%;
        text-align: right;
    }

    .col-importe {
        width: 26%;
        text-align: right;
    }

    img.qr {
        display: block;
        margin: 6px auto;
        width: 30mm;
        height: 30mm;
    }

    @media screen {
        body {
            background: #e5e7eb;
        }

        .hoja {
            width: {{ $anchoPapel->value }}mm;
            margin: 12px auto;
            padding: 2mm;
            background: #fff;
            box-shadow: 0 0 6px rgba(0, 0, 0, .2);
        }
    }
</style>
