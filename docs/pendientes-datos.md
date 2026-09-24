# Pendientes de infraestructura para Datos

Generado al cerrar `auth-prompt.md` (endurecimiento de auth de
`zx-dashboard-vibe`). Actualizado 2026-09-24 tras validar en producción.

## Resuelto

- **Permiso IAM para firmar URLs sin key file** -- `zx-dashboard-runtime@
  epa-turing.iam.gserviceaccount.com` tiene `roles/iam.
  serviceAccountTokenCreator` (otorgado a nivel de proyecto). Confirmado
  con una firma real contra el bucket, `curl` a la URL firmada devuelve
  200.
- **Bucket de imágenes cerrado** -- se sacó el binding `allUsers` /
  `roles/storage.objectViewer` de `zx-dashboard-creative-images`.
  Confirmado: la URL pública ahora da 403, la URL firmada sigue dando
  200. `zx-dashboard-runtime` mantiene `roles/storage.objectAdmin` para
  el pipeline de ingesta (`ImagenCacheService`) y para firmar.
- **`TrustProxies` validado contra Cloud Run** -- el header
  `Strict-Transport-Security` aparece en las respuestas reales, confirma
  que `$request->secure()` detecta `https://` correctamente a través del
  proxy de borde de Cloud Run (GFE).
- **CSP promovida a enforced** -- se confirmó que `script-src`/
  `style-src 'unsafe-inline'` ya cubren los dos `<script>` inline de
  `app.blade.php` (tema, Ziggy `@routes`) y que `img-src` coincide con
  el formato real que genera `StorageObject::signedUrl()` del SDK de PHP
  (`storage.googleapis.com/{bucket}/...`, no el subdominio que usa por
  default el CLI de `gcloud`). La cabecera pasó de
  `Content-Security-Policy-Report-Only` a `Content-Security-Policy`.
- **Env vars de sesión** -- `SESSION_LIFETIME=60`, `SESSION_ENCRYPT=true`,
  `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`,
  `SESSION_SAME_SITE=lax` ya están en el step "Deploy a Cloud Run" de
  `deploy.yml` y se aplicaron en el primer deploy de esta rama.
- **Recuperación de contraseña sin SMTP (2026-09-24)** -- `MAIL_MAILER=log`
  sigue en producción y así se queda, decisión explícita: en vez de un
  proveedor de correo real, la recuperación de acceso para
  `metodo_auth=password` es manual. El link de "olvidé mi contraseña"
  (`/forgot-password`) se ocultó (`canResetPassword=false`, ver
  `AuthenticatedSessionController::create()`) porque sin correo real era
  un callejón sin salida silencioso -- Laravel mostraba el mensaje de
  éxito genérico igual, aunque nada llegara nunca. En su lugar,
  `UsuariosController::resetearPassword()` (gerente/director/superadmin,
  botón "Resetear contraseña" en `/usuarios`) reusa el mismo mecanismo de
  invitación (token hasheado + 72h) -- un admin genera el link y lo manda
  a mano (Slack/WhatsApp), igual que ya se hacía para invitar. El TOTP
  existente no se toca; al aceptar el link sigue pidiendo el segundo
  factor antes de abrir sesión (nunca alcanza con solo la contraseña
  nueva). Si en algún momento se quiere volver a un flujo de
  autoservicio por correo, hay que revisar si alguna variable `AWS_*` de
  `.env.example` (scaffold de Laravel, sin usar hoy) corresponde a una
  cuenta SES real de EPA, elegir proveedor, y volver `canResetPassword`
  a `true`.

## Pendiente

### 1. Reemplazar `GCP_SA_KEY` por Workload Identity Federation

`.github/workflows/deploy.yml` autentica a GitHub Actions contra GCP con
una key JSON de service account guardada en un secret de GitHub. El
patrón recomendado por Google es Workload Identity Federation (OIDC, sin
llave de larga duración que rotar ni que se pueda filtrar).

- Acción: crear un Workload Identity Pool + Provider para este repo en
  `epa-turing`, actualizar el step de auth del workflow para usar
  `google-github-actions/auth` con `workload_identity_provider` en vez
  de `credentials_json`.
- No es urgente para que el login funcione -- es hardening del pipeline
  de CI/CD, no del producto.

## Qué NO es pendiente (ya resuelto en código)

- Password policy (`min:12`, mayúsculas+minúsculas, números,
  `uncompromised()`) -- vía `Password::defaults()`.
- TOTP obligatorio + códigos de recuperación hasheados + anti-replay.
- Bitácora de auditoría (`auditoria_accesos`).
- Invitaciones con token hasheado + expiración de 72h.
