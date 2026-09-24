# Pendientes de infraestructura para Datos

Generado al cerrar `auth-prompt.md` (endurecimiento de auth de
`zx-dashboard-vibe`). Todo lo de acá requiere `gcloud`/cambios de
infraestructura que este trabajo **no ejecutó** -- solo el código quedó
listo, en la rama `feature/auth-hardening`, sin pushear a `main`.

## 1. Sacar el acceso público del bucket de imágenes

`zx-dashboard-creative-images` hoy tiene lectura pública a nivel IAM
(bucket-level, uniform bucket-level access -- ver comentario en
`AppServiceProvider::register()`). El código ya sirve las imágenes de
creativos con URLs firmadas de 15 minutos (`ImagenFirmadaService`, Fase
6), generadas solo después de que `EnsureAccesoPais` valida el país --
así que el acceso público del bucket ya no hace falta para que la app
funcione.

- Acción: quitar el binding IAM de lectura pública (`allUsers`/
  `allAuthenticatedUsers`) del bucket.
- Orden sugerido: primero confirmar en producción que las imágenes cargan
  bien vía URL firmada (ver punto 4 de abajo, la firma necesita un
  permiso IAM que probablemente falte), y recién después cerrar el
  bucket -- si se cierra antes de que la firma funcione, todas las
  imágenes de creativos se rompen.

## 2. Proveedor de correo real (SMTP)

`MAIL_MAILER=log` en producción -- el reset de contraseña
(`NewPasswordController`) hoy no entrega ningún correo real, solo lo deja
en el log. Con `metodo_auth=password` ahora siendo una opción real para
clientes sin Google (Fase 3), esto deja de ser un detalle menor.

- Acción: elegir proveedor (SES, Postmark, Resend, lo que ya use el resto
  de EPA) y crear el secreto correspondiente en Secret Manager con el
  patrón `ZxDashboard*` (ej. `ZxDashboardMailPassword` o el que
  corresponda a las credenciales del proveedor elegido).
- Revisar si alguna de las variables `AWS_*` que ya trae `.env.example`
  (scaffold de Laravel, sin usar hoy) corresponde a una cuenta SES real
  de EPA o es config muerta -- no se pudo confirmar desde este entorno.

## 3. Reemplazar `GCP_SA_KEY` por Workload Identity Federation

`.github/workflows/deploy.yml` autentica a GitHub Actions contra GCP con
una key JSON de service account guardada en un secret de GitHub
(`GCP_SA_KEY` o similar, revisar el step de auth del workflow). El
patrón recomendado por Google es Workload Identity Federation (OIDC, sin
llave de larga duración que rotar ni que se pueda filtrar).

- Acción: crear un Workload Identity Pool + Provider para este repo en
  `epa-turing`, actualizar el step de auth del workflow para usar
  `google-github-actions/auth` con `workload_identity_provider` en vez
  de `credentials_json`.
- No es urgente para que el login funcione -- es hardening del pipeline
  de CI/CD, no del producto.

## 4. Permiso IAM para firmar URLs sin key file

`ImagenFirmadaService` (Fase 6) firma URLs de GCS usando Application
Default Credentials (la service account de runtime del servicio, sin key
file explícito) -- esto requiere que esa service account tenga el
permiso `iam.serviceAccounts.signBlob` sobre sí misma (típicamente vía el
rol `roles/iam.serviceAccountTokenCreator`), porque sin una key file
local, `signedUrl()` firma a través de la API de IAM en vez de una
firma local.

- Acción: otorgar `roles/iam.serviceAccountTokenCreator` a la service
  account de runtime (`RUNTIME_SA` en `deploy.yml`) sobre sí misma.
- Sin este permiso, `ImagenFirmadaService::firmar()` falla (queda
  registrado en el log como warning) y las imágenes de creativos no
  cargan -- no se pudo probar en este entorno por falta de credenciales
  reales de GCP.

## 5. Env vars nuevas agregadas a `deploy.yml` (ya editado, no ejecutado)

Se agregaron al step "Deploy a Cloud Run": `SESSION_LIFETIME=60`,
`SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`,
`SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`. No requieren ningún
secreto nuevo, son variables de entorno simples -- se aplican solas la
próxima vez que se despliegue desde `main`.

## 6. Validar `TrustProxies` contra el proxy real de Cloud Run

`bootstrap/app.php` acota los headers que confía `TrustProxies` a
`X-Forwarded-For/Host/Port/Proto` (antes confiaba en todos, sin acotar).
Sigue usando `at: '*'` porque Cloud Run no publica una IP fija para su
proxy de borde (GFE) -- confiar por IP no es una opción ahí, y `at: '*'`
es el patrón recomendado para este ingress específico. Aun así, esto no
se pudo probar contra el proxy real.

- Acción: después de desplegar, confirmar que `URL::to('/')` y
  `$request->getSchemeAndHttpHost()` devuelven `https://` (no `http://`)
  y que `$request->ip()` da la IP real del cliente, no la del proxy
  interno de Cloud Run.

## 7. CSP en Report-Only -- promover a enforced

El middleware `SecurityHeaders` (Fase 4) manda
`Content-Security-Policy-Report-Only` en vez de `Content-Security-Policy`
-- `app.blade.php` tiene un `<script>` inline (tema antes del primer
paint) y Ziggy (`@routes`) inyecta otro, ninguno de los dos se pudo
validar contra una CSP estricta sin desplegar contra el dominio real.

- Acción: una vez en producción, revisar en la consola del navegador
  (o configurar un `report-uri`/`report-to`) qué bloquearía la política
  actual, ajustar (probablemente agregando un nonce a esos dos
  `<script>` en vez de depender de `'unsafe-inline'`), y recién ahí
  cambiar la cabecera a `Content-Security-Policy` (enforced).

## Qué NO es pendiente (ya resuelto en código)

- Password policy (`min:12`, mayúsculas+minúsculas, números,
  `uncompromised()`) -- vía `Password::defaults()`.
- TOTP obligatorio + códigos de recuperación hasheados + anti-replay.
- Bitácora de auditoría (`auditoria_accesos`).
- Invitaciones con token hasheado + expiración de 72h.
