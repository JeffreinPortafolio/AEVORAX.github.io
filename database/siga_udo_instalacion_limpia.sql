-- SIGA-UDO · Instalación limpia (solo administrador y configuración)
-- Instalación: importar en phpMyAdmin. Crea la base `siga_udo` y reemplaza las tablas existentes.
-- Basado en un volcado de phpMyAdmin
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 29-06-2026 a las 22:21:29
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `siga_udo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `siga_udo`;

--
-- Base de datos: `siga_udo`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `activos`
--

DROP TABLE IF EXISTS `activos`;
CREATE TABLE `activos` (
  `id_activo` int(11) NOT NULL,
  `codigo_udo` varchar(50) NOT NULL,
  `tipo_equipo` varchar(50) NOT NULL,
  `marca` varchar(100) NOT NULL,
  `modelo` varchar(100) NOT NULL,
  `serial` varchar(100) NOT NULL,
  `ubicacion` varchar(255) NOT NULL,
  `departamento` varchar(255) DEFAULT NULL,
  `responsable_actual` varchar(255) DEFAULT NULL,
  `cedula_responsable` varchar(20) DEFAULT NULL,
  `fecha_adquisicion` date DEFAULT NULL,
  `valor_compra` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado_equipo` enum('Operativo','En Recepción','En Reparación','Reparado','Baja Técnica','Desincorporado') NOT NULL DEFAULT 'Operativo',
  `observaciones` text DEFAULT NULL,
  `foto_url` varchar(255) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `ultima_modificacion` datetime DEFAULT NULL,
  `registrado_por` int(11) DEFAULT NULL,
  `modificado_por` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `activos`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `auditoria`
--

DROP TABLE IF EXISTS `auditoria`;
CREATE TABLE `auditoria` (
  `id_auditoria` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `usuario_nombre` varchar(100) NOT NULL,
  `usuario_rol` varchar(50) NOT NULL,
  `accion` text NOT NULL,
  `tabla_afectada` varchar(100) DEFAULT NULL,
  `registro_id` int(11) DEFAULT NULL,
  `datos_anteriores` text DEFAULT NULL,
  `datos_nuevos` text DEFAULT NULL,
  `ip_usuario` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `fecha_hora` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `auditoria`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion`
--

DROP TABLE IF EXISTS `configuracion`;
CREATE TABLE `configuracion` (
  `id_config` int(11) NOT NULL,
  `clave` varchar(100) NOT NULL,
  `valor` text DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `configuracion`
--

INSERT INTO `configuracion` (`id_config`, `clave`, `valor`, `descripcion`, `fecha_actualizacion`) VALUES
(1, 'nombre_sistema', 'SIGA-UDO', 'Nombre corto', '2026-06-29 20:20:34'),
(2, 'nombre_completo', 'Sistema de Gestión de Activos - Universidad de Oriente', 'Nombre completo', '2026-06-29 20:20:34'),
(3, 'nombre_institucion', 'Universidad de Oriente - Núcleo Monagas', 'Institución', '2026-06-29 20:20:34'),
(4, 'slogan', 'Del pueblo venimos, hacia el pueblo vamos', 'Lema', '2026-06-29 20:20:34'),
(5, 'correo_envio', 'jeffreinbarreto@gmail.com', 'Correo de envío', '2026-06-29 20:20:34'),
(6, 'pass_correo', '', 'Contraseña de aplicación', '2026-06-29 20:20:34'),
(7, 'intentos_login', '3', 'Intentos máximos', '2026-06-29 20:20:34'),
(8, 'tiempo_bloqueo', '15', 'Minutos de bloqueo', '2026-06-29 20:20:34'),
(9, 'duracion_sesion', '0', 'Minutos de inactividad', '2026-06-29 20:20:34'),
(10, 'modo_mantenimiento', '0', 'Modo mantenimiento', '2026-06-29 20:20:34'),
(11, 'mensaje_mantenimiento', 'Sistema en mantenimiento. Intente más tarde.', 'Mensaje', '2026-06-29 20:20:34'),
(12, 'mantenimiento_inicio', '', 'Inicio programado', '2026-06-29 20:20:34'),
(13, 'mantenimiento_fin', '', 'Fin programado', '2026-06-29 20:20:34'),
(14, 'clave_desbloqueo_mantenimiento', 'admin1234', 'Clave de desbloqueo', '2026-06-29 20:20:34'),
(15, 'dias_retencion_logs', '365', 'Días de retención de auditoría', '2026-06-29 20:20:34'),
(16, 'logo_url', '', 'Ruta del logo', '2026-06-29 20:20:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mensajes`
--

DROP TABLE IF EXISTS `mensajes`;
CREATE TABLE `mensajes` (
  `id_mensaje` int(11) NOT NULL,
  `remitente_id` int(11) NOT NULL,
  `destinatario_id` int(11) NOT NULL,
  `asunto` varchar(255) DEFAULT NULL,
  `contenido` text DEFAULT NULL,
  `imagen_url` varchar(255) DEFAULT NULL,
  `fecha_envio` timestamp NOT NULL DEFAULT current_timestamp(),
  `leido` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_leido` datetime DEFAULT NULL,
  `eliminado_para_remitente` tinyint(1) NOT NULL DEFAULT 0,
  `eliminado_para_destinatario` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `mensajes`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `novedades`
--

DROP TABLE IF EXISTS `novedades`;
CREATE TABLE `novedades` (
  `id_novedad` int(11) NOT NULL,
  `id_activo` int(11) NOT NULL,
  `numero_orden` varchar(50) NOT NULL,
  `descripcion_falla` text NOT NULL,
  `persona_reporta` varchar(255) NOT NULL,
  `telefono_reporta` varchar(20) DEFAULT NULL,
  `accesorios` varchar(255) DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_entrada` datetime NOT NULL,
  `diagnostico_tecnico` text DEFAULT NULL,
  `nivel_dano` enum('Leve','Moderado','Grave','Irreparable') DEFAULT NULL,
  `causa_probable` varchar(255) DEFAULT NULL,
  `fecha_diagnostico` datetime DEFAULT NULL,
  `id_tecnico_diagnostico` int(11) DEFAULT NULL,
  `id_tecnico_inicio_reparacion` int(11) DEFAULT NULL,
  `diagnostico_iniciado_por` int(11) DEFAULT NULL,
  `diagnostico_fecha_inicio` datetime DEFAULT NULL,
  `fecha_inicio_reparacion` datetime DEFAULT NULL,
  `fecha_fin_reparacion` datetime DEFAULT NULL,
  `solucion_aplicada` text DEFAULT NULL,
  `horas_trabajadas` decimal(5,2) NOT NULL DEFAULT 0.00,
  `estado_final` enum('Reparado','No Reparable') DEFAULT NULL,
  `fecha_salida` datetime DEFAULT NULL,
  `fecha_entrega_final` datetime DEFAULT NULL,
  `persona_recibe` varchar(255) DEFAULT NULL,
  `cedula_recibe` varchar(20) DEFAULT NULL,
  `tecnico_asignado` int(11) DEFAULT NULL,
  `motivo_baja` text DEFAULT NULL,
  `causa_especifica` varchar(255) DEFAULT NULL,
  `costo_reparacion_estimado` decimal(12,2) DEFAULT NULL,
  `porcentaje_costo_reparacion` int(11) DEFAULT NULL,
  `dictamen_tecnico` text DEFAULT NULL,
  `foto_evidencia` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `novedades`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `oficios`
--

DROP TABLE IF EXISTS `oficios`;
CREATE TABLE `oficios` (
  `id_oficio` int(11) NOT NULL,
  `numero_oficio` varchar(50) NOT NULL,
  `id_activo` int(11) NOT NULL,
  `id_novedad` int(11) NOT NULL,
  `motivo_baja` text NOT NULL,
  `causa_especifica` varchar(255) DEFAULT NULL,
  `porcentaje_costo_reparacion` int(11) DEFAULT NULL,
  `dictamen_tecnico` text NOT NULL,
  `generado_por` int(11) DEFAULT NULL,
  `fecha_generacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` varchar(20) NOT NULL DEFAULT 'activo',
  `foto_evidencia` varchar(255) DEFAULT NULL,
  `disposicion_final` text DEFAULT NULL,
  `fecha_disposicion` datetime DEFAULT NULL,
  `responsable_disposicion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `oficios`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expiracion` datetime NOT NULL,
  `usado` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `repuestos`
--

DROP TABLE IF EXISTS `repuestos`;
CREATE TABLE `repuestos` (
  `id_repuesto` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `descripcion` text DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `repuestos`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sesiones_activas`
--

DROP TABLE IF EXISTS `sesiones_activas`;
CREATE TABLE `sesiones_activas` (
  `id_sesion` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha_inicio` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `uso_repuestos`
--

DROP TABLE IF EXISTS `uso_repuestos`;
CREATE TABLE `uso_repuestos` (
  `id_uso` int(11) NOT NULL,
  `id_novedad` int(11) NOT NULL,
  `id_repuesto` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `uso_repuestos`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id_usuario` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `clave` varchar(255) NOT NULL,
  `rol` enum('administrador','tecnico','recepcionista','consultor') NOT NULL,
  `estado` enum('activo','inactivo','pendiente') NOT NULL DEFAULT 'pendiente',
  `telefono` varchar(20) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `ultimo_acceso` datetime DEFAULT NULL,
  `ultima_ip` varchar(45) DEFAULT NULL,
  `creado_por` int(11) DEFAULT 0,
  `primer_acceso` tinyint(1) NOT NULL DEFAULT 0,
  `ultima_actividad` datetime DEFAULT NULL,
  `pregunta1` varchar(255) DEFAULT NULL,
  `respuesta1_hash` varchar(255) DEFAULT NULL,
  `pregunta2` varchar(255) DEFAULT NULL,
  `respuesta2_hash` varchar(255) DEFAULT NULL,
  `pregunta3` varchar(255) DEFAULT NULL,
  `respuesta3_hash` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id_usuario`, `nombre`, `email`, `cedula`, `clave`, `rol`, `estado`, `telefono`, `direccion`, `cargo`, `foto`, `fecha_nacimiento`, `fecha_creacion`, `ultimo_acceso`, `ultima_ip`, `creado_por`, `primer_acceso`, `ultima_actividad`, `pregunta1`, `respuesta1_hash`, `pregunta2`, `respuesta2_hash`, `pregunta3`, `respuesta3_hash`) VALUES
(1, 'Jeffrein2804', 'jeffreinbarreto@gmail.com', '12345678', '$2y$10$/eIUedbKxWZCuKcyOILm2e4fXQF9jvseyG72HUE8G3jYvZ/N59lae', 'administrador', 'activo', '0412-1234567', 'Núcleo Monagas', 'Administrador del Sistema', '', '1990-01-01', '2026-06-29 20:20:34', NULL, NULL, 0, 0, NULL, '¿Color favorito?', '$2y$10$Qq.V1EoSL6VQt//O4uVRDeqA0TcrmgdJWJ6Fc87Uh.P.7AYoQbhMa', '¿Modelo de tu primer carro?', '$2y$10$xJpOBWZYC44knZLMlD906eT69O/S5lOOY0BQ30lLy.QUFPelqAN.K', '¿Comida favorita?', '$2y$10$m3njz5vA5.b7Xr5pPb7gfOFLGCN5829iXY.YQggO0FrOxHDOPVSJC');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `vouchers`
--

DROP TABLE IF EXISTS `vouchers`;
CREATE TABLE `vouchers` (
  `id_voucher` int(11) NOT NULL,
  `tipo` enum('recepcion','entrega') NOT NULL,
  `id_novedad` int(11) NOT NULL,
  `titulo` varchar(100) DEFAULT NULL,
  `generado_por` int(11) NOT NULL,
  `fecha_generacion` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `vouchers`
--


--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `activos`
--
ALTER TABLE `activos`
  ADD PRIMARY KEY (`id_activo`),
  ADD UNIQUE KEY `codigo_udo` (`codigo_udo`),
  ADD KEY `registrado_por` (`registrado_por`),
  ADD KEY `modificado_por` (`modificado_por`),
  ADD KEY `idx_codigo` (`codigo_udo`),
  ADD KEY `idx_estado` (`estado_equipo`);

--
-- Indices de la tabla `auditoria`
--
ALTER TABLE `auditoria`
  ADD PRIMARY KEY (`id_auditoria`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `idx_fecha` (`fecha_hora`);

--
-- Indices de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  ADD PRIMARY KEY (`id_config`),
  ADD UNIQUE KEY `clave` (`clave`);

--
-- Indices de la tabla `mensajes`
--
ALTER TABLE `mensajes`
  ADD PRIMARY KEY (`id_mensaje`),
  ADD KEY `idx_remitente` (`remitente_id`),
  ADD KEY `idx_destinatario` (`destinatario_id`);

--
-- Indices de la tabla `novedades`
--
ALTER TABLE `novedades`
  ADD PRIMARY KEY (`id_novedad`),
  ADD UNIQUE KEY `numero_orden` (`numero_orden`),
  ADD KEY `id_activo` (`id_activo`),
  ADD KEY `creado_por` (`creado_por`),
  ADD KEY `id_tecnico_diagnostico` (`id_tecnico_diagnostico`),
  ADD KEY `diagnostico_iniciado_por` (`diagnostico_iniciado_por`),
  ADD KEY `idx_orden` (`numero_orden`),
  ADD KEY `idx_fecha` (`fecha_entrada`),
  ADD KEY `tecnico_asignado` (`tecnico_asignado`);

--
-- Indices de la tabla `oficios`
--
ALTER TABLE `oficios`
  ADD PRIMARY KEY (`id_oficio`),
  ADD UNIQUE KEY `numero_oficio` (`numero_oficio`),
  ADD KEY `id_activo` (`id_activo`),
  ADD KEY `id_novedad` (`id_novedad`),
  ADD KEY `generado_por` (`generado_por`);

--
-- Indices de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`);

--
-- Indices de la tabla `repuestos`
--
ALTER TABLE `repuestos`
  ADD PRIMARY KEY (`id_repuesto`);

--
-- Indices de la tabla `sesiones_activas`
--
ALTER TABLE `sesiones_activas`
  ADD PRIMARY KEY (`id_sesion`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `uso_repuestos`
--
ALTER TABLE `uso_repuestos`
  ADD PRIMARY KEY (`id_uso`),
  ADD KEY `id_novedad` (`id_novedad`),
  ADD KEY `id_repuesto` (`id_repuesto`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `idx_cedula` (`cedula`),
  ADD KEY `idx_estado` (`estado`);

--
-- Indices de la tabla `vouchers`
--
ALTER TABLE `vouchers`
  ADD PRIMARY KEY (`id_voucher`),
  ADD KEY `tipo` (`tipo`),
  ADD KEY `id_novedad` (`id_novedad`),
  ADD KEY `generado_por` (`generado_por`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `activos`
--
ALTER TABLE `activos`
  MODIFY `id_activo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `auditoria`
--
ALTER TABLE `auditoria`
  MODIFY `id_auditoria` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  MODIFY `id_config` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT de la tabla `mensajes`
--
ALTER TABLE `mensajes`
  MODIFY `id_mensaje` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `novedades`
--
ALTER TABLE `novedades`
  MODIFY `id_novedad` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `oficios`
--
ALTER TABLE `oficios`
  MODIFY `id_oficio` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `repuestos`
--
ALTER TABLE `repuestos`
  MODIFY `id_repuesto` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `sesiones_activas`
--
ALTER TABLE `sesiones_activas`
  MODIFY `id_sesion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `uso_repuestos`
--
ALTER TABLE `uso_repuestos`
  MODIFY `id_uso` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `vouchers`
--
ALTER TABLE `vouchers`
  MODIFY `id_voucher` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `activos`
--
ALTER TABLE `activos`
  ADD CONSTRAINT `activos_ibfk_1` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL,
  ADD CONSTRAINT `activos_ibfk_2` FOREIGN KEY (`modificado_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL;

--
-- Filtros para la tabla `auditoria`
--
ALTER TABLE `auditoria`
  ADD CONSTRAINT `auditoria_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL;

--
-- Filtros para la tabla `mensajes`
--
ALTER TABLE `mensajes`
  ADD CONSTRAINT `mensajes_ibfk_1` FOREIGN KEY (`remitente_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE,
  ADD CONSTRAINT `mensajes_ibfk_2` FOREIGN KEY (`destinatario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `novedades`
--
ALTER TABLE `novedades`
  ADD CONSTRAINT `novedades_ibfk_1` FOREIGN KEY (`id_activo`) REFERENCES `activos` (`id_activo`) ON DELETE CASCADE,
  ADD CONSTRAINT `novedades_ibfk_2` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL,
  ADD CONSTRAINT `novedades_ibfk_3` FOREIGN KEY (`id_tecnico_diagnostico`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL,
  ADD CONSTRAINT `novedades_ibfk_4` FOREIGN KEY (`diagnostico_iniciado_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL,
  ADD CONSTRAINT `novedades_ibfk_5` FOREIGN KEY (`tecnico_asignado`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL;

--
-- Filtros para la tabla `oficios`
--
ALTER TABLE `oficios`
  ADD CONSTRAINT `oficios_ibfk_1` FOREIGN KEY (`id_activo`) REFERENCES `activos` (`id_activo`) ON DELETE CASCADE,
  ADD CONSTRAINT `oficios_ibfk_2` FOREIGN KEY (`id_novedad`) REFERENCES `novedades` (`id_novedad`) ON DELETE CASCADE,
  ADD CONSTRAINT `oficios_ibfk_3` FOREIGN KEY (`generado_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL;

--
-- Filtros para la tabla `sesiones_activas`
--
ALTER TABLE `sesiones_activas`
  ADD CONSTRAINT `sesiones_activas_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `uso_repuestos`
--
ALTER TABLE `uso_repuestos`
  ADD CONSTRAINT `uso_repuestos_ibfk_1` FOREIGN KEY (`id_novedad`) REFERENCES `novedades` (`id_novedad`) ON DELETE CASCADE,
  ADD CONSTRAINT `uso_repuestos_ibfk_2` FOREIGN KEY (`id_repuesto`) REFERENCES `repuestos` (`id_repuesto`);

--
-- Filtros para la tabla `vouchers`
--
ALTER TABLE `vouchers`
  ADD CONSTRAINT `vouchers_ibfk_1` FOREIGN KEY (`id_novedad`) REFERENCES `novedades` (`id_novedad`) ON DELETE CASCADE,
  ADD CONSTRAINT `vouchers_ibfk_2` FOREIGN KEY (`generado_por`) REFERENCES `usuarios` (`id_usuario`);
COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- --------------------------------------------------------
-- Usuario de MySQL/MariaDB para la aplicación (core/conexion.php lo usa)
-- Se crea al importar este archivo con un usuario con privilegios (root en XAMPP).
-- --------------------------------------------------------
CREATE USER IF NOT EXISTS 'Jeffrein2804'@'localhost' IDENTIFIED BY 'Jeffrein2804';
ALTER USER 'Jeffrein2804'@'localhost' IDENTIFIED BY 'Jeffrein2804';
GRANT ALL PRIVILEGES ON `siga_udo`.* TO 'Jeffrein2804'@'localhost';
FLUSH PRIVILEGES;
