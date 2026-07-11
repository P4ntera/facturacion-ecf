# Recepción de e-CF: URLs a registrar en la DGII

Este ERP puede **recibir** e-CF que otras empresas nos envían (y aprobaciones comerciales sobre
los e-CF que nosotros emitimos). La DGII entrega esos documentos llamando a dos URLs que **nosotros
registramos** en el portal de la DGII (Oficina Virtual, sección de configuración del contribuyente
para e-CF). Esas URLs son las de nuestra propia aplicación — no las del PAC.

## URLs a registrar

Con el dominio público de producción (`APP_URL` en el `.env` de producción), las URLs son:

- **Recepción**: `https://<dominio-de-produccion>/fe/recepcion/api/ecf`
- **Aprobación comercial**: `https://<dominio-de-produccion>/fe/aprobacioncomercial/api/ecf`

Ambas son rutas `POST` de esta app (ver `routes/web.php`, controladores `RecepcionEcfController` y
`AprobacionComercialEcfController`). Aceptan el XML como cuerpo `application/xml` o como multipart
con un campo `xml`.

**Importante:** estas URLs solo funcionan si el dominio de producción:

- Es públicamente alcanzable por internet (la DGII las llama desde sus propios servidores, no
  desde nuestra red interna).
- Tiene HTTPS válido (certificado no autofirmado).
- No tiene el sitio detrás de una pantalla de mantenimiento/autenticación que bloquee peticiones
  sin sesión (estas rutas ya están excluidas de CSRF en `bootstrap/app.php`, pero cualquier
  middleware adicional que se agregue al `web` group — ej. un firewall de aplicación, HTTP Basic
  Auth a nivel de servidor — debe excluirlas también).

Mientras el dominio de producción no esté decidido/desplegado, **no tiene sentido registrar nada
en la DGII todavía** — regístralas recién cuando el ambiente de producción esté arriba y accesible.

## Qué hace esta app al recibir un documento

1. Valida tamaño (máx. 5 MB) y que el RNC del XML (comprador o emisor, según el documento)
   corresponda al RNC configurado en **Configuración → Datos de la Empresa**.
2. Si pasa la validación, reenvía el XML **tal cual** (sin transformarlo) al PAC configurado en
   **Configuración → Datos de la Empresa → Integración e-CF**, usando la API Key de esa
   configuración.
3. Registra cada intento (aceptado o rechazado) en **Compras → e-CF Recibidos** — incluye el XML
   recibido, los metadatos que se pudieron leer (emisor, e-NCF, tipo, monto, fecha) y la respuesta
   del PAC. La API Key nunca se guarda ni se registra ahí.

Ver `app/Services/Dgii/RecepcionEcfService.php` para el detalle de la validación, y
`app/Services/Dgii/DgiiGatewayInterface.php` (`reenviarRecepcion`/`reenviarAprobacionComercial`)
para el reenvío al PAC.

## Probar el flujo sin la DGII real

En `local` (o con `DGII_FAKE_GATEWAY=true`), el reenvío usa `FakeGateway` — no llama a ningún PAC
real. Se puede simular una recepción con `curl` contra el Sail local:

```bash
curl -i -X POST http://localhost:8088/fe/recepcion/api/ecf \
  -H "Content-Type: application/xml" \
  --data-binary @ruta/al/ejemplo.xml
```

El XML de prueba debe traer `RNCComprador` (o `RNCEmisor`) igual al RNC configurado en
**Configuración → Datos de la Empresa**, o la petición se rechaza con 422.

## Pendiente antes de producción

- [ ] Definir y desplegar el dominio público de producción.
- [ ] Registrar las dos URLs en la Oficina Virtual de la DGII.
- [ ] Confirmar con el PAC contratado el formato exacto que espera en
      `POST {base}/{rnc}/fe/recepcion/api/ecf` y `.../fe/aprobacioncomercial/api/ecf` (esta
      implementación asume que reenviar el XML tal cual, con la API Key en el header `X-API-Key`,
      es suficiente — confirmarlo con la documentación específica del PAC contratado).
