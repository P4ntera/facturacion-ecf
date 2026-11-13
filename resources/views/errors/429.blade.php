@include('errors.erp', [
    'codigo' => 429,
    'icono' => '⏳',
    'titulo' => 'Demasiados intentos',
    'mensaje' => 'Se hicieron muchas solicitudes en poco tiempo. Espere un minuto e intente de nuevo.',
    'recargar' => true,
])
