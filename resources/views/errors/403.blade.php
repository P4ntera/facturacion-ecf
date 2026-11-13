@include('errors.erp', [
    'codigo' => 403,
    'icono' => '🔒',
    'titulo' => 'No tiene acceso a esta página',
    'mensaje' => 'Su usuario no tiene permiso para ver esta página, o el registro pertenece a otra empresa. Si cree que es un error, pida acceso al administrador.',
    'recargar' => false,
])
