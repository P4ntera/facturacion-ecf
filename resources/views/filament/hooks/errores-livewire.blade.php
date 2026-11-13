{{--
    Errores de Livewire legibles para el usuario.

    Por defecto, cuando una acción falla (500, 403, sin red...) Livewire abre una ventana con la
    página de error cruda, y la sesión expirada sale en inglés. Aquí se reemplaza por un aviso en
    español que dice qué pasó y qué hacer. El detalle técnico queda en el log del servidor.
    Sirve igual en instalación local y en la nube (donde "sin conexión" sí puede pasar).
--}}
<script>
    document.addEventListener('livewire:init', () => {
        const avisos = {
            sinRed: ['Sin conexión con el servidor', 'No se guardó la última acción. Revise la red o el servidor e intente de nuevo.'],
            403: ['No se pudo completar la acción', 'No tiene permiso para hacerlo, o el sistema está en modo solo lectura.'],
            404: ['Registro no encontrado', 'El registro ya no existe o fue modificado. Recargue la página.'],
            413: ['Archivo demasiado grande', 'Reduzca el tamaño del archivo e intente de nuevo.'],
            429: ['Demasiados intentos', 'Espere un momento antes de volver a intentar.'],
            servidor: ['Ocurrió un error inesperado', 'No se guardó la última acción. Intente de nuevo; si se repite, contacte a soporte.'],
        };

        let ultimoAviso = { clave: null, hora: 0 };

        // El aviso se dibuja aquí mismo, sin pasar por las notificaciones de Filament: esas
        // necesitan otra ida al servidor, que es justo lo que acaba de fallar.
        const contenedor = () => {
            let caja = document.getElementById('erp-avisos-error');

            if (! caja) {
                caja = document.createElement('div');
                caja.id = 'erp-avisos-error';
                caja.setAttribute('role', 'alert');
                caja.setAttribute('aria-live', 'assertive');
                caja.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2147483000;display:flex;flex-direction:column;gap:8px;max-width:min(380px,calc(100vw - 32px));';
                document.body.appendChild(caja);
            }

            return caja;
        };

        const avisar = (clave) => {
            // Un componente con sondeo (wire:poll) fallaría cada segundo: un solo aviso por causa.
            const ahora = Date.now();
            if (ultimoAviso.clave === clave && ahora - ultimoAviso.hora < 8000) return;
            ultimoAviso = { clave, hora: ahora };

            const [titulo, cuerpo] = avisos[clave];

            const aviso = document.createElement('div');
            aviso.style.cssText = 'background:#7f1d1d;color:#fff;border-radius:10px;padding:14px 16px;box-shadow:0 8px 24px rgba(0,0,0,.35);font:14px/1.45 Inter,system-ui,sans-serif;display:flex;gap:12px;align-items:flex-start;';

            const texto = document.createElement('div');
            texto.style.flex = '1';
            const fuerte = document.createElement('strong');
            fuerte.style.cssText = 'display:block;font-size:15px;margin-bottom:2px;';
            fuerte.textContent = titulo;
            const detalle = document.createElement('span');
            detalle.textContent = cuerpo;
            texto.append(fuerte, detalle);

            const cerrar = document.createElement('button');
            cerrar.type = 'button';
            cerrar.setAttribute('aria-label', 'Cerrar aviso');
            cerrar.textContent = '✕';
            cerrar.style.cssText = 'background:transparent;border:0;color:#fff;font-size:16px;cursor:pointer;min-width:32px;min-height:32px;';
            cerrar.addEventListener('click', () => aviso.remove());

            aviso.append(texto, cerrar);
            contenedor().appendChild(aviso);
            setTimeout(() => aviso.remove(), 10000);
        };

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, content, preventDefault }) => {
                if (status === 419) {
                    preventDefault();

                    if (window.confirm('Su sesión expiró por inactividad.\n¿Recargar la página para continuar?')) {
                        window.location.reload();
                    }

                    return;
                }

                // Livewire reporta 503 sin contenido cuando la petición ni siquiera llegó al servidor.
                const sinRed = navigator.onLine === false || (status === 503 && ! content);
                const clave = sinRed ? 'sinRed' : (avisos[status] ? status : 'servidor');

                preventDefault();
                avisar(clave);
            });
        });
    });
</script>
