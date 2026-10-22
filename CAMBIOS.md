# Revisión v5 · Fluidez, seguridad y limpieza

## Interfaz / scroll
- Menú lateral: la posición se restaura antes del primer pintado (script en `header.php`); ya no salta ni se reinicia.
- Eliminado `scroll-behavior: smooth` global y el `autofocus` de Recepción. Las alertas se colapsan suavemente.
- CSS más ligero: sin `backdrop-filter`, sin animaciones infinitas, entradas de página cortas, transiciones concretas.
- La configuración se lee con 1 consulta por petición (antes ~13).
- Perfil: la foto se reduce en el navegador antes de subir; corregido XSS en nombre y datos.

## Seguridad
- `restringirAcceso()`: revalida usuario activo y rol en la BD (cada 60 s), huella del navegador, roles válidos.
- Cookies de sesión HttpOnly + SameSite; `login/logout/olvide` ya no inician sesión antes de `funciones.php`.
- Salidas escapadas con `e()`; JS embebido con `json_encode`; rol/estado de usuario con lista blanca.
- Errores internos al log (`errorInterno()`), mensaje genérico al usuario.
- Quitada la contraseña de Gmail que estaba escrita como valor por defecto en `olvide_mi_contrasena.php`.

## Limpieza
- Eliminadas todas las exportaciones a Excel/CSV (Reportes y Auditoría).
- Eliminada la opción «Forzar mantenimiento» (selector, `?forzar_mantenimiento=0` y modo manual). Solo queda el rango programado.

## Imágenes
- `procesarImagenSubida()` (core/funciones.php): valida contenido real, corrige orientación, reduce y recomprime (JPG/WEBP 78, PNG nivel 8), nombre aleatorio. Usada en todas las subidas. GIF se guarda sin recomprimir.

---

# Revisión v4 · Azul eléctrico

## Base de datos
- `siga_udo.sql` y `siga_udo_instalacion_limpia.sql`: el administrador pasa a llamarse `Jeffrein2804` y su clave
  es `Jeffrein2804` (hash bcrypt, no texto plano). Se crea el usuario MySQL `Jeffrein2804` con permisos sobre `siga_udo`.
- `core/conexion.php` usa ese usuario (constantes al inicio) y ya no muestra el error técnico al visitante.

## Interfaz
- Paleta violeta/fucsia → azul eléctrico + cian en todo el proyecto (claro y oscuro).
- Efectos nuevos en `theme.css` (sección 11): foco de luz que sigue al cursor en las tarjetas, ondas en botones,
  botón principal que respira, spinner al enviar, modales con barra de luz, revelado al hacer scroll (`data-reveal`).
- **Login** rediseñado: panel de marca animado + tarjeta de vidrio con borde giratorio, etiquetas flotantes,
  ver/ocultar clave, sacudida al fallar. Acepta usuario **o** correo.
- **Registro**: asistente de 3 pasos (datos · cuenta · seguridad) con validación en vivo, medidor de fuerza de clave
  y preguntas de seguridad que no se pueden repetir.
- **Recuperar contraseña**: misma interfaz. Se eliminó CSS suelto que se mostraba como texto en la página.

## Código limpio
- `login.php`: de 900 líneas a ~520; CSS en `assets/css/auth.css`, JS en `assets/js/auth.js`.
  Las 11 funciones `validarX()` repetidas son ahora una tabla de reglas (`RULES`).
- Corregido: `$modo_mantenimiento` no estaba definida en el login.
- Quitados los `<script>` en línea de `header.php` y `footer.php` (ahora en `ui.js`) y 12 `oninput` repetidos
  (ahora `data-filter="digits|letters|..."`).
- Eliminados 10 archivos duplicados idénticos (`core/PHPMailer/*.php` fuera de `src/`, README/LICENSE de dompdf).

---

# Cambios de esta revisión

## Fallos corregidos (por qué "ningún módulo funcionaba")
1. **Tablas desaparecían**: `lista`, `usuarios`, `auditoria` e `historial_vouchers` cambiaban a "vista de tarjetas"
   cuando `window.devicePixelRatio >= 1.1` (escalado de Windows 125 %/150 % o zoom del navegador ≥ 110 %).
   Se eliminó ese script; ahora la vista depende **solo del ancho de pantalla** (< 992 px = tarjetas).
2. **Recepción y Bajas fallaban al guardar**: llamaban a `validarCsrfToken()`, función inexistente. Se añadió a `core/funciones.php`.
3. **Taller (reparar.php)**: faltaba `calcularPorcentajeBajaRep()` (error JS al escribir el costo) y
   `toggleBajaFields()` marcaba como obligatorios campos opcionales (foto, costo). Corregido.
4. **Detalle de activo**: incluía el header (HTML) antes de las redirecciones → "headers already sent". Reordenado.
5. `usuarios.php`: se conservó intacto el JS de modales/fechas al quitar la lógica de zoom.

## Interfaz nueva
- `assets/css/theme.css` reescrito: tokens claro/oscuro, escala tipográfica, tamaños de controles uniformes,
  animaciones (entrada de página, filas, modales, dropdowns, tarjetas), tablas con cabecera fija y scroll interno.
- `assets/js/ui.js` (nuevo): envuelve tablas en contenedor con scroll, anima filas y contadores, menú móvil con overlay.
- Panel (`index.php`): tarjetas de estadística nuevas con contador animado y rejilla responsive.
- Menú lateral: overlay y cierre con Esc / al navegar en móvil.
- Modo claro: corregidos botones `btn-outline-light` y textos `text-dark` que quedaban invisibles u oscuros sobre fondo oscuro.
