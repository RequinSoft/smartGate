# SmartGate

Aplicación Laravel 13, Livewire 4 y MySQL/MariaDB para administrar RFID y consultar eventos de control de acceso. Interfaz en español, permisos con **Spatie Permission**, bitácora con **Spatie Activitylog** y respaldos con **Spatie Backup**.

## Funciones

- Autenticación sin registro público, limitación de intentos y usuarios con roles Administrador, Operador y Auditor.
- Personal local e importación paginada desde HikCentral Professional 3.0.0.
- Vehículos, tarjetas decimales conservadas como texto y asignaciones históricas persona/vehículo.
- Alta y revocación RFID en cola, confirmación individual por equipo y reintentos visibles.
- Consulta incremental de eventos ISAPI, deduplicación y cursor actualizado solo al completar todas las páginas.
- Seguimiento por persona, vehículo, dispositivo, RFID y fechas; exportación CSV auditada.
- Auditoría de cambios, autenticación, importación, envío, revocación, lectura, exportación y respaldos.
- ZIP cifrados de base de datos y archivos privados, programación de limpieza y monitoreo.

## Requisitos e instalación

PHP 8.4 o superior para las dependencias fijadas (validado con PHP 8.5), Composer 2, Node compatible con Vite 8 (22.12+), MySQL 8 o MariaDB compatible. Extensiones PHP habituales de Laravel, PDO MySQL, cURL, mbstring, XML, ZIP y fileinfo. `mysqldump` debe estar disponible para los respaldos.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Cree una base de datos **exclusiva de la aplicación**, configure `DB_*` con un usuario de privilegios limitados y luego ejecute:

```bash
php artisan migrate --seed
php artisan smartgate:admin admin@su-dominio.com --name="Administrador"
```

El comando solicita una contraseña de al menos 12 caracteres; no existen contraseñas predeterminadas. La semilla crea roles y permisos, no usuarios de demostración. Mantenga `.env` y `APP_KEY` fuera del control de versiones.

Para desarrollo: `php artisan serve`. En producción sirva únicamente `public/` mediante HTTPS, use `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` y configure el correo. No publique el directorio raíz ni el almacenamiento de respaldos.

El usuario del servidor web y el de los workers necesitan escritura en `storage/` y `bootstrap/cache/`. Use un grupo compartido y permisos adecuados, no permisos globales `777`. Si ejecuta Artisan con otro usuario, asegure que los logs creados por ambos sigan siendo escribibles.

## Colas y programación

Las integraciones y los respaldos no se ejecutan dentro de la petición web. Configure Supervisor/systemd para mantener un worker y cron para el scheduler:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=1800
# Cron, cada minuto, con la ruta real de instalación:
* * * * * cd /ruta/smartGate && php artisan schedule:run >> /var/log/smartgate-scheduler.log 2>&1
```

`DB_QUEUE_RETRY_AFTER` debe ser mayor que el timeout máximo (predeterminado: 1860 segundos). Al desplegar cambios ejecute `php artisan queue:restart`. Los estados `pending`, `applied`, `failed` y `cancelled` son distintos: **una solicitud en cola no significa éxito remoto**.

Revise `php artisan queue:failed`, la pantalla de auditoría, el error del equipo y los logs privados. El botón Reintentar vuelve a encolar una operación RFID fallida. Para trabajos fallidos de lectura/importación use `php artisan queue:retry ID` después de resolver la causa. No ejecute simultáneamente reintentos masivos y correcciones manuales en equipos.

## HikCentral Professional 3.0.0

Referencia de implementación: [RequinSoft/interfazH](https://github.com/RequinSoft/interfazH), firma Artemis y consulta de personal. No se ha probado contra un servidor HikCentral real en este entorno.

Configure:

```dotenv
HIKCENTRAL_URL=https://hikcentral.interno:443
HIKCENTRAL_KEY=
HIKCENTRAL_SECRET=
HIKCENTRAL_CA_BUNDLE=/ruta/ca-interna.pem
```

`HIKCENTRAL_URL` es el origen del servidor, **sin `/artemis`**. La aplicación agrega `/artemis/api/resource/v1/person/personList`. Usa POST JSON y firma HMAC-SHA256/Base64 con `x-ca-key`. La validación TLS permanece activa y no se siguen redirecciones.

Desde HikCentral puede solicitar la importación en la interfaz o ejecutarla sin cola:

```bash
php artisan smartgate:import-people
```

El contrato esperado es `code: "0"` y `data: { total, list }`, con `personId`, `personCode`, `personName` y opcionalmente `orgIndexCode`. **personId y personCode no se consideran equivalentes**. Si su instalación no devuelve `personId`, no se inventa: ajuste el adaptador con una respuesta documentada y una prueba. Los conflictos detienen la importación; las filas previamente procesadas permanecen guardadas y la operación es repetible. No borra ausentes, no reactiva inactivos, no importa tarjetas y no altera asignaciones locales.

## Dispositivos Hikvision y límites de compatibilidad

Modelos informados: DS-K1T671TM-3XF, DS-K1T342MFWX-E1, DS-K1T341CMFW y DS-K280120220621V. **La compatibilidad depende del firmware y de las capacidades reales; ninguno fue validado físicamente durante el desarrollo.** El identificador DS-K280120220621V podría incluir un número de serie: confirme modelo/firmware. Los controladores DS-K2801 pueden requerir SDK u otro protocolo y no deben asumirse compatibles con ISAPI.

Registre el dispositivo con su URL base, credenciales y ubicación. Las contraseñas se cifran con `APP_KEY` y no se incluyen en la bitácora. Use una cuenta técnica de mínimos privilegios y una red de administración restringida. Se permite HTTP para hardware legado, pero se recomienda HTTPS con `HIKVISION_CA_BUNDLE`.

Lectura y escritura están **deshabilitadas por defecto**. Antes de habilitarlas:

1. Consulte capacidades y firmware en un equipo de ensayo.
2. Confirme autenticación HTTP Digest y las rutas/contratos de `config/hikvision.php`.
3. Compruebe un evento conocido y el formato de tarjeta decimal, incluidos ceros iniciales. No hay conversión automática desde UID hexadecimal ni entre tecnologías RFID.
4. Verifique que el número de empleado de SmartGate coincide exactamente con `employeeNo` del equipo.
5. Pruebe alta, lectura, revocación y confirmación remota. No valide contra un punto de acceso crítico en operación.

El adaptador consulta:

- `POST /ISAPI/AccessControl/AcsEvent?format=json`: eventos de acceso, `major=5`, todos los subtipos.
- `POST /ISAPI/AccessControl/UserInfo/Search?format=json`: verifica el empleado existente.
- `POST /ISAPI/AccessControl/CardInfo/Search?format=json`: verifica la titularidad remota antes de modificar o revocar.
- `PUT /ISAPI/AccessControl/CardInfo/SetUp?format=json`: vincula la tarjeta.
- `PUT /ISAPI/AccessControl/CardInfo/Delete?format=json`: revoca la tarjeta.

**No crea usuarios en los equipos ni modifica planes, vigencias, permisos de puerta o apertura.** Distribuya primero el personal y sus permisos desde HikCentral. Si el empleado no existe en el equipo, el envío falla explícitamente. Esto evita sobrescribir la configuración de acceso existente.

Si la tarjeta ya pertenece a otro empleado en el equipo, SmartGate detiene tanto el alta como la baja. Concilie esa diferencia manualmente antes de reintentar. Una baja de una tarjeta verificada como ausente es idempotente.

Las asignaciones locales pueden guardarse sin equipos seleccionados; no equivalen a tarjetas instaladas. Para enviarlas seleccione equipos habilitados al asignar. Una revocación solicita la baja en todos los equipos registrados en esa asignación. No se permite reutilizar el RFID mientras quede alguna baja sin confirmar. Si un equipo está fuera de línea, **la tarjeta puede seguir activa físicamente** hasta confirmar la revocación.

La primera lectura recupera aproximadamente las últimas 24 horas; las siguientes incluyen un solapamiento de 5 minutos. La retención del equipo, los reinicios y las interrupciones prolongadas pueden causar pérdida de eventos que ya no estén disponibles. No hay recuperación histórica ilimitada ni conexión mediante SDK en esta versión.

## Interpretación de movimientos

- Todas las fechas, filtros y tareas programadas usan **UTC**. Configure NTP en servidores y equipos.
- La atribución RFID usa la asignación vigente en la fecha del evento, no el propietario actual. Los eventos recibidos antes de registrar la asignación permanecen sin atribuir; no se reescribe evidencia automáticamente.
- Un vehículo se identifica por la asociación histórica del RFID, no mediante lectura de placa ni detección física independiente.
- `major:minor` conserva el código del equipo. Un evento rechazado no es un paso confirmado. El sentido entrada/salida corresponde a la configuración del punto, no se infiere del código.
- Las tarjetas desconocidas no se atribuyen al empleado actual como alternativa. No hay seguimiento GPS ni ubicación fuera de los puntos de lectura.
- CSV incluye el día final completo y neutraliza fórmulas de hoja de cálculo. Importe las columnas RFID/empleado como texto para no perder ceros iniciales.

## Roles y auditoría

| Rol | Acceso |
|---|---|
| Administrador | Todos los módulos, usuarios, integraciones y respaldos |
| Operador | Personal, vehículos, RFID y consulta de movimientos |
| Auditor | Consulta de movimientos, exportación y bitácora |

Los permisos se verifican en el servidor para cada acción, no solamente al ocultar botones. No hay borrado de personas, vehículos, asignaciones ni movimientos desde la interfaz. La bitácora conserva actor, fecha, origen, IP y cambios, excluyendo contraseñas. Las solicitudes web se vinculan con su actor y entidad; los trabajos ejecutados por cola se registran como sistema.

La base de datos **no es un almacén criptográficamente inmutable**: un administrador de base de datos puede alterarla. Para requisitos regulatorios configure exportación externa WORM/solo anexado, políticas de conservación, monitoreo, privilegios separados y pruebas de restauración. Limite acceso a datos personales, documente la finalidad y defina retención según su normativa. No se incluye eliminación automática de evidencia.

## Respaldos y restauración

Configure una contraseña fuerte e independiente:

```dotenv
BACKUP_ARCHIVE_PASSWORD=
BACKUP_NOTIFICATION_EMAIL=operaciones@su-dominio.com
```

La pantalla encola un respaldo; el job rechaza la ejecución sin contraseña y registra fallos. Respalda base de datos y `storage/app/private`, verifica el ZIP y lo almacena en el disco privado `backups` (`storage/app/backups`). No incluye `.env`, código, dependencias ni repositorio Git. Los respaldos no son accesibles mediante `storage:link`.

La programación es: respaldo 02:00, limpieza Spatie 03:00 y monitoreo 04:00 UTC. Ajuste retención en `config/backup.php`. Configure transporte de correo real; el mailer `log` de desarrollo no envía alertas. Evite llamar directamente `backup:run` sin configurar el cifrado.

**Antes de producción añada un destino externo** a Filesystem y a los discos de Backup/Monitor, con el driver correspondiente. El disco local no protege ante pérdida del servidor. Guarde `APP_KEY`, las variables de integración y la contraseña del ZIP en un gestor de secretos separado: sin `APP_KEY` no se recuperan las credenciales cifradas de dispositivos.

Restauración manual y controlada:

1. Pruebe primero en un entorno aislado sin conectividad a los equipos.
2. Detenga workers y scheduler, y ponga la aplicación en mantenimiento.
3. Recupere y descifre un ZIP verificado con una herramienta compatible con AES; restaure su dump en una base vacía y sus archivos privados.
4. Restaure la configuración segura y el `APP_KEY` original; despliegue la misma revisión del código y sus dependencias.
5. Valide usuarios, asignaciones, bitácora y fechas. No reproduzca trabajos RFID antiguos sin conciliarlos con el estado físico de los equipos.
6. Reactive workers/scheduler solo después de reconciliar los accesos. Documente la restauración en la bitácora operativa.

## Pruebas

```bash
php artisan test
vendor/bin/pint --test
npm run build
```

PHPUnit usa SQLite en memoria por defecto (requiere PDO SQLite). También puede usar **una base MySQL/MariaDB desechable y exclusiva de pruebas**:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=smartgate_test \
DB_USERNAME=smartgate_test DB_PASSWORD=su_clave vendor/bin/phpunit
```

Nunca ejecute las pruebas contra una base compartida o de producción: `RefreshDatabase` ejecuta migraciones y puede reconstruir tablas. Las pruebas de integración usan respuestas simuladas para HikCentral e ISAPI, incluido el desafío Digest; no sustituyen la validación con hardware.
