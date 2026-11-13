# SmartGate

Aplicación Laravel 13, Livewire 4 y MySQL/MariaDB para administrar RFID y consultar eventos de control de acceso. Interfaz en español, permisos con **Spatie Permission**, bitácora con **Spatie Activitylog** y respaldos con **Spatie Backup**.

## Funciones

- Autenticación sin registro público, limitación de intentos y usuarios con roles Administrador, Operador y Auditor.
- Personal local e importación paginada desde HikCentral Professional 3.0.0.
- Administrativos > Grupos permite consultar y buscar los grupos recibidos de interfazH, con su grupo padre, origen, ID externo y número de personas asignadas directamente.
- Vehículos con placa, marca, modelo, versión, color y fotografía opcional (con imagen predeterminada), tarjetas decimales conservadas como texto y asignaciones históricas persona/vehículo.
- Los enlaces Personal y Vehículos del bloque Operativos muestran solo registros activos. El bloque Inactivos contiene Personal inactivo y Vehículos inactivos, con los mismos permisos y edición para reactivarlos. El estado de un vehículo se cambia desde su formulario; los vehículos existentes se conservan activos al actualizar.
- El dashboard cuenta únicamente personal y vehículos activos; conserva los movimientos históricos de todos los registros.
- Cada vehículo se clasifica como Personal o De la empresa desde su formulario; el tipo se muestra en ambos listados. Los vehículos existentes quedan Sin clasificar hasta que se editen.
- Alta y revocación RFID en cola, confirmación individual por equipo y reintentos visibles.
- En Operativos, RFID permite registrar tarjetas sin asignarlas mediante un botón y modal. Asignaciones RFID mantiene el historial, revocaciones y confirmaciones; su botón Añadir asignación abre un modal para seleccionar un RFID previamente registrado, una persona y opcionalmente un vehículo y dispositivos.
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

El usuario del servidor web y el de los workers necesitan escritura en `storage/` y `bootstrap/cache/`. Las fotografías de vehículos se guardan en el almacenamiento privado y solo las sirve la aplicación a usuarios con permiso para gestionar vehículos. Use un grupo compartido y permisos adecuados, no permisos globales `777`. Si ejecuta Artisan con otro usuario, asegure que los logs creados por ambos sigan siendo escribibles.

## Colas y programación

### Host virtual Apache local

Se incluye `deploy/apache/smartgate.conf` para `http://smartgate.localhost`, con
DocumentRoot limitado a `public/`, sin listado de directorios y logs separados.
Apache debe tener PHP y `mod_rewrite` habilitados. Para instalar y activar:

```bash
sudo install -m 644 deploy/apache/smartgate.conf /etc/apache2/sites-available/smartgate.conf
sudo a2ensite smartgate.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Configure `APP_URL=http://smartgate.localhost` y ejecute `php artisan config:clear`.
Los dominios `.localhost` normalmente resuelven a loopback sin editar hosts; si
su sistema no lo resuelve, agregue `127.0.0.1 smartgate.localhost` a `/etc/hosts`.
Este sitio es HTTP de desarrollo local; para producción configure un dominio
real y TLS. La base de datos debe estar configurada y migrada para iniciar sesión.

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

## Recepción de datos de interfazH

SmartGate expone una API para recibir los recursos que envía interfazH. En SmartGate, abra **API interfazH** en el bloque administrativo y genere un token; cópielo en ese momento y configúrelo como token de conexión SmartGate en interfazH. SmartGate almacena únicamente el hash, por lo que no se puede volver a consultar: genere uno nuevo para rotarlo. Puede revocarlo desde la misma pantalla. Use HTTPS fuera del entorno local y mantenga el secreto fuera del repositorio. Si no hay un token activo, la API responde `503`.

| Método y ruta | Función |
|---|---|
| `POST /api/connection` | Verifica la conexión y el token |
| `POST /api/devices` | Recibe dispositivos |
| `POST /api/doors` | Recibe puertas |
| `POST /api/groups` | Recibe grupos |
| `POST /api/personnel` | Recibe personal |
| `POST /api/events` | Recibe eventos |

Las rutas de recursos aceptan JSON con `source` y un arreglo `data` de hasta 100 registros, cada uno con un `id`:

```json
{"source":"attendance","data":[{"id":1,"personCode":"00123","complete_name":"Ejemplo"}]}
```

Para `personnel`, cada registro debe incluir también `personCode` (texto) y `complete_name`. SmartGate usa `personCode` como `employee_number`, conservando ceros iniciales, y `complete_name` como nombre. El `id` numérico se conserva únicamente como identificador externo del registro. Si el número de empleado ya existe, solo se actualiza el nombre; se conservan el departamento, el vínculo con HikCentral, el estado y las asignaciones locales. Una persona nueva queda activa, sin inventar departamento ni `hikcentral_id`. Esta importación ocurre en la misma transacción que la recepción: si un registro no es válido, no se acepta parcialmente el lote.

Para `groups`, cada registro requiere `name` y acepta `parent_id` opcional. Se guardan en la tabla `groups` (identificados por `source` + `external_id`, el `id` de interfazH) conservando la jerarquía. El `group_id` opcional de `personnel` enlaza la persona mediante `people.group_id`; enviar `null` la desvincula. Si una persona o un subgrupo llega antes que su grupo, se crea un grupo provisional `Grupo {id}` que se completa al recibir el grupo.

Las personas cuyo grupo se llama `BAJA` o `BAJAS` (sin distinguir mayúsculas) se inactivan y se revocan sus RFID activos. Si después interfazH las mueve a otro grupo, se reactivan; una persona inactivada manualmente fuera de bajas no se reactiva. Si un grupo se renombra a `BAJAS`, se inactivan todas sus personas.

Para `devices`, cada registro requiere `name` y acepta `ip_address`, `port`, `code` (serie), `type` y `status` (`1` = en línea). Se guardan en `devices` identificados por `source` + `external_id`; el modelo se deduce de la serie (por ejemplo `DS-K1T341CMFW`). Las sincronizaciones posteriores solo actualizan nombre, IP, puerto, serie, tipo y estado: la URL ISAPI, las credenciales, la ubicación, el sentido y la habilitación de lectura/escritura se configuran en SmartGate y nunca se sobrescriben. Un equipo importado queda sin lectura ni escritura hasta capturar su URL ISAPI, usuario y contraseña.

Envíe `Authorization: Bearer <token>` y `Content-Type: application/json`. Una recepción correcta responde `{"success":true,"accepted":1}`; la ruta de conexión responde `{"success":true}`. Los registros se guardan en `integration_records`, identificados por origen, recurso e ID externo: volver a enviar un lote actualiza los registros existentes sin duplicarlos. Además del payload original, el personal se importa al catálogo local; los demás recursos siguen disponibles en la bandeja de integraciones y no configuran permisos ni equipos.

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

## Configuración

El bloque **Configuración** del menú (permiso `settings.manage`, otorgado al rol Administrador) agrupa:

- **Respaldos**.
- **Correo SMTP**: servidor, puerto, seguridad (`smtp` = STARTTLS/sin cifrado, `smtps` = SSL/TLS implícito), usuario, contraseña (cifrada con `APP_KEY`, nunca se muestra ni se audita) y remitente. Incluye envío de un correo de prueba. Mientras no se configure se usa `MAIL_*` del `.env`.
- **Zona horaria**: los registros se guardan siempre en UTC; la zona configurada se usa para mostrar fechas, filtrar movimientos por día, exportar el CSV y programar tareas (respaldos a las 02:00 de esa zona).

Los ajustes se guardan en la tabla `settings`. Al guardarlos se envía la señal de `queue:restart` para que los workers carguen la nueva configuración.

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
