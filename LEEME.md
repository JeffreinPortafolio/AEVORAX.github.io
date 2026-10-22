# SIGA-UDO — Sistema de Gestión de Activos

## Instalación (XAMPP)
1. Copia la carpeta `Asset-UDO` dentro de `htdocs` (la URL base es `/Asset-UDO`).
2. En phpMyAdmin, **Importar** uno de estos archivos de `database/`:
   - `siga_udo.sql` — esquema actualizado + datos de demostración (10 activos, 5 órdenes).
   - `siga_udo_instalacion_limpia.sql` — solo el usuario administrador y la configuración.
3. Si ya tienes una base en uso y no quieres perder datos, ejecuta únicamente
   `migracion_esquema_actual.sql` (MariaDB; se puede repetir sin problema).
   Al importar con **root** se crea también el usuario de MySQL `Jeffrein2804` (clave `Jeffrein2804`) con
   permisos sobre `siga_udo`; es el que usa `core/conexion.php`.
4. Entra a `http://localhost/Asset-UDO/` con:
   - **Usuario:** `Jeffrein2804` (o el correo `jeffreinbarreto@gmail.com`)
   - **Clave:** `Jeffrein2804`
5. En **Configuración → Correo** escribe la contraseña de aplicación de Gmail (el SQL ya no la incluye).

Credenciales de la base: constantes `DB_USER` / `DB_PASS` al inicio de `core/conexion.php`.

## Estructura
```
auth/        inicio de sesión, cierre y recuperación de clave
core/        conexión, funciones comunes, dompdf, PHPMailer
includes/    header, footer, tema, formato de documentos PDF
modules/     activos · admin · mensajes · recepcion · tecnico
reports/     reporte general y ficha individual
assets/      css (theme.css = panel, auth.css = acceso), js (ui.js, auth.js, csrf.js, theme-toggle.js), imágenes y uploads
database/    scripts SQL
```

## Notas de seguridad
- Configure la contraseña de aplicación de Gmail en **Configuración → Correo** (ya no hay valor por defecto).
- Cambie la clave de desbloqueo de mantenimiento (`admin1234` por defecto) en **Configuración → Mantenimiento**.
- Requiere la extensión GD de PHP para comprimir imágenes (activa por defecto en XAMPP).
