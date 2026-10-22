-- SIGA-UDO · Migración para una base de datos ya existente (MariaDB 10.x / XAMPP)
-- Añade lo que el código actual necesita y el volcado antiguo no tenía. Se puede ejecutar varias veces.
USE `siga_udo`;

-- 1) El estado «Baja Técnica» no existía en el ENUM: diagnóstico/taller fallaban o guardaban un valor vacío.
ALTER TABLE `activos`
  MODIFY `estado_equipo` enum('Operativo','En Recepción','En Reparación','Reparado','Baja Técnica','Desincorporado') NOT NULL DEFAULT 'Operativo';
-- Los activos que quedaron con estado vacío por ese error vuelven a «Baja Técnica» (pendientes de aprobación).
UPDATE `activos` SET `estado_equipo` = 'Baja Técnica' WHERE `estado_equipo` = '';

-- 2) Columnas que usan recepción, diagnóstico, taller y bajas
ALTER TABLE `novedades`
  ADD COLUMN IF NOT EXISTS `tecnico_asignado` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `motivo_baja` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `causa_especifica` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `costo_reparacion_estimado` decimal(12,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `porcentaje_costo_reparacion` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dictamen_tecnico` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `foto_evidencia` varchar(255) DEFAULT NULL;

ALTER TABLE `oficios`
  ADD COLUMN IF NOT EXISTS `foto_evidencia` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `disposicion_final` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `fecha_disposicion` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `responsable_disposicion` varchar(255) DEFAULT NULL;

-- 3) Fechas de mantenimiento guardadas con «T» (formato del navegador) -> formato de MySQL
UPDATE `configuracion` SET `valor` = REPLACE(`valor`, 'T', ' ')
 WHERE `clave` IN ('mantenimiento_inicio', 'mantenimiento_fin') AND `valor` <> '';
