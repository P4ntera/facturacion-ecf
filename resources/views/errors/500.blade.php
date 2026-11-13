@include('errors.erp', [
    'codigo' => 500,
    'icono' => '⚠️',
    'titulo' => 'Ocurrió un error inesperado',
    'mensaje' => 'No se guardó la última acción. Intente de nuevo; si el problema se repite, contacte a soporte e indique la hora en que ocurrió.',
    'recargar' => true,
])
