# NutriFlow AI Context

## Objetivo del proyecto
NutriFlow es un backend API en Symfony para una app de nutricion y entrenamiento.

Dominios principales:
- rutinas de entrenamiento
- sesiones y sets de workout
- planes de dieta
- diario de comidas
- mediciones corporales
- catalogo de ejercicios
- catalogo local de alimentos y servings
- logging de errores del backend

La API esta pensada para ser consumida por una app cliente autenticada con Firebase.

## Stack tecnico
- PHP `>=8.1`
- Symfony `6.4`
- Doctrine ORM `3.6`
- Doctrine Migrations
- MySQL `8.0`
- Autenticacion Firebase con `kreait/firebase-php`
- Tests con PHPUnit `10.5`

## Estructura del proyecto
- `src/Controller`: endpoints HTTP
- `src/Entity`: modelo de dominio y mapping Doctrine
- `src/Service`: logica de aplicacion reutilizable
- `src/Security`: autenticacion real y autenticacion de test
- `src/EventListener`: listener global de excepciones
- `src/Doctrine/Type`: tipos Doctrine custom
- `migrations`: esquema y seeds
- `tests/Api`: tests funcionales HTTP
- `tests/Domain`: tests de dominio
- `tests/Doctrine`: tests de infraestructura Doctrine

## Autenticacion
Produccion:
- el firewall principal usa `App\Security\FirebaseAuthenticator`
- los usuarios se identifican por `firebaseUid`

Tests:
- se usa `App\Security\TestHeaderAuthenticator`
- para autenticar en tests basta con enviar `X-Test-User`
- opcionalmente `X-Test-Email`

## Base de datos y UUIDs
Este proyecto guarda UUIDs en MySQL como `CHAR(36)`.

Importante:
- Doctrine por defecto puede intentar tratar `uuid` como binario en MySQL
- este proyecto ya lo corrige con `App\Doctrine\Type\UuidCharType`
- el override esta registrado en `config/packages/doctrine.yaml`

Si una IA toca IDs o relaciones, no debe volver a asumir UUID binario.

## Configuracion sensible
- `config/firebase_credentials.json` debe permanecer fuera de git
- existe `config/firebase_credentials.example.json` como plantilla
- la ruta del JSON puede sobrescribirse con `FIREBASE_CREDENTIALS_PATH`
- la DB se configura con `DATABASE_URL`

## Endpoints actuales
### Diaries
- `GET /v1/diaries/{date}`
- `POST /v1/diaries/{date}/entries`
- `DELETE /v1/diaries/entries/{id}`
- La creacion admite `client_id` UUID estable para reintentos; el borrado repetido de una entrada ausente devuelve 204.

### Diet Plans
- `GET /v1/diet-plans`
- `GET /v1/diet-plans/{id}`
- `POST /v1/diet-plans`
- `PUT /v1/diet-plans/{id}`
- `DELETE /v1/diet-plans/{id}`
- `POST /v1/diet-plans/{id}/apply`

### Error Logs
- `GET /v1/errors`
- `GET /v1/errors/{id}`

### Exercises
- `GET /v1/exercises`
- `GET /v1/exercises/search`
- `GET /v1/exercises/{id}`
- `POST /v1/exercises` (ROLE_ADMIN)
- `PUT /v1/exercises/{id}` (ROLE_ADMIN)
- `DELETE /v1/exercises/{id}` (ROLE_ADMIN)
- Las lecturas siguen disponibles para usuarios autenticados.
- Lista y búsqueda mantienen payload de array y orden estable por nombre y UUID. La búsqueda admite `page >= 1`, `limit` entre 1 y 200 (por defecto 20), con `X-Total-Count`, `X-Page` y `X-Per-Page`; el cliente debe recorrer páginas para cargar todo el catálogo. La búsqueda ES/EN combina tokens significativos sin depender del orden sobre nombre, nombre original, equipo e instrucciones; normaliza mayúsculas/tildes, incorpora sinónimos y trata `%`/`_` como texto literal.
- Para acotar la expansión SQL, las consultas admiten como máximo 200 caracteres Unicode (sin espacios exteriores) y 20 términos significativos distintos tras normalizar y quitar conectores. Superar cualquiera de esos límites devuelve JSON 400 antes de construir la consulta; los límites exactos siguen siendo válidos.
- La importacion Hevy solo crea ejercicios ausentes si el usuario tiene ROLE_ADMIN; para el resto exige que todos existan antes de escribir sesiones.

#### Catálogo abierto y expansión no destructiva
- Origen: [yuhonas/free-exercise-db](https://github.com/yuhonas/free-exercise-db), revisión fija `f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5`, 876 identidades únicas en `resources/exercises/free-exercise-db.f00c92c7.json`. No requiere suscripción, clave de proveedor ni acceso de red para importar; no se utiliza ni se raspa MuscleWiki.
- Licencia del proyecto de origen: [Unlicense de esa revisión](https://github.com/yuhonas/free-exercise-db/blob/f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5/LICENSE.md), dedicación al dominio público y distribución «AS IS», sin garantías. Copia literal en `resources/exercises/free-exercise-db.LICENSE`; conservar esta procedencia al redistribuir la instantánea. Las imágenes apuntan a archivos reales del mismo repositorio y revisión, no a contenido de otro proveedor.
- Preparar el esquema con `php bin/console doctrine:migrations:migrate --no-interaction` (incluye `Version20261020000100`, solo añade `source_id` nullable único y `source_name` nullable; no importa ni modifica ejercicios existentes).
- Antes de simular/importar, regenerar los metadatos del entorno desplegado (`php bin/console cache:clear --env=prod --no-debug`). Ejecutar migraciones, caché e importación con el mismo `--env`; una caché antigua puede omitir `source_id` al persistir y romper la idempotencia.
- Simular: `php bin/console app:import-exercises --dry-run`. Importar: `php bin/console app:import-exercises`. El comando valida los 876 registros y sus tipos antes de escribir, rechaza instantáneas parciales, IDs repetidos y estructuras inválidas, y aplica el plan en una sola transacción. Instrucciones o imágenes ausentes en la fuente se conservan como desconocidas, no cancelan ejercicios válidos. Un error revierte toda la importación. La simulación informa nuevos, vinculados, ya importados y coincidencias ambiguas, sin escribir.
- Identidad estable: `sourceId = free-exercise-db:<id original>`; `sourceName` conserva el nombre inglés auténtico. Repetir la importación crea 0 para identidades ya importadas y no sobrescribe ninguna edición. Nunca borra ejercicios, cambia UUIDs ni toca referencias de rutinas o historial.
- Solo se vincula un ejercicio previo sin origen cuando hay una coincidencia inequívoca: ruta exacta de imagen de free-exercise-db (host/repositorio y revisión fijada o `main` del seed histórico), o nombre original/español conocido normalizado + equipamiento equivalente exacto. Normalización limitada a mayúsculas, tildes y espacios; no hay búsqueda difusa. Si varias filas previas o varios orígenes compiten, no se fusionan: se crea un registro separado y se informa la ambigüedad. Identidades `source_id` duplicadas en el catálogo son un error, nunca se elige una arbitrariamente.
- Al vincular solo se añaden `sourceId` y `sourceName`; se conservan nombre español, instrucciones, grupos, equipo, imagen/GIF/vídeo y tracking, incluso si están vacíos. Los registros nuevos usan instrucciones originales reales (omitiendo párrafos vacíos; `null` si no hay texto), primer músculo primario traducido o grupo `Cardio` para esa categoría, equipo normalizado (`null` sigue desconocido), y primera imagen real fijada a la revisión (`null` si no hay imagen). No se inventan GIF/vídeos. Se traducen únicamente variantes conocidas; las demás mantienen el nombre inglés. `Dumbbell_Bench_Press` se muestra como **Press banca plano con mancuernas**.
- Tracking inicial: fuerza con carga usa peso/repeticiones; fuerza corporal y saltos sin carga, repeticiones; estiramientos/isométricos, duración; cardio continuo, distancia/duración; cuerda, duración; transportes/arrastres cargados, peso/distancia. No se convierte el abdomen corporal en cardio ni el cardio de máquina en fuerza con peso. La adopción y las reimportaciones respetan siempre el tracking existente.
- Límites del modelo: se guarda un solo grupo muscular (primer primario salvo categoría Cardio; secundarios no persistidos), `other` sigue como `Otro` y equipo desconocido como `null`. No existe tracking combinado peso+duración: un isométrico cargado usa duración, sin inventar una nueva unidad. El tracking inicial es una regla por categoría/equipo con excepciones identificadas (rueda/soportes corporales, transportes y cuerda); no es una clasificación clínica de cada variante. Puede ajustarse en el catálogo y futuras importaciones no lo pisan.
- `app:seed-exercises` permanece como herramienta histórica separada de inicialización española; no es el mecanismo de expansión ni se invoca desde el importador. No usar su opción destructiva `--force` en un catálogo con rutinas/historial. El flujo normal para ampliar un catálogo existente es `app:import-exercises`.

### Foods
- `GET /v1/foods/search`
- `GET /v1/foods/{id}`
- `GET /v1/foods/barcode/{barcode}`: consulta Open Food Facts; devuelve nutrientes por 100 g/ml, unidad verificada y `canImport`. Nutrientes desconocidos son `null`; 404 significa producto ausente y 502/504 error/timeout del proveedor.
- `POST /v1/foods/barcode/{barcode}`: importa en el catalogo personal del usuario autenticado, sin aceptar macros enviados por el cliente. Repite la consulta al proveedor y rechaza datos incompletos con 422; devolver un alimento previamente importado no lo duplica.

### Saved Meals / Recetas
- `GET /v1/saved-meals`, `GET /v1/saved-meals/{id}`
- `POST /v1/saved-meals`, `PUT /v1/saved-meals/{id}`, `DELETE /v1/saved-meals/{id}`
- Las recetas reutilizan comidas guardadas: `name`, `items`, `servings` (raciones enteras 1..100), `instructions` (preparacion, hasta 20000 caracteres), `preferredMealType`. La creacion admite `client_id` UUID idempotente.
- Los ingredientes contienen `servingId`, `amountGrams` y nutrientes de la cantidad completa; si estan vinculados al catalogo el servidor calcula los nutrientes desde la racion accesible. Las recetas usan gramos, sin asumir equivalencia gramos/mililitros.
- `POST /v1/diaries/{date}/saved-meals/{id}` acepta `mealType`, `servings` (raciones consumidas, tambien fraccionarias) y `client_id` UUID opcional para reintentos idempotentes; escala todos los ingredientes por consumidas / raciones de la receta. Conservar el mismo UUID hasta confirmar exito para una misma receta, fecha, comida y cantidad.
- Migracion requerida: `Version20261019000200`, anade `saved_meals.servings` (1 para comidas existentes) e `instructions` (texto vacio para existentes). Ejecutar las migraciones antes de desplegar el frontend de recetas.

### Measurements
- `GET /v1/measurements`
- `POST /v1/measurements`
- `PUT /v1/measurements/{id}`
- `DELETE /v1/measurements/{id}`

### Routines
- `GET /v1/routines`
- `GET /v1/routines/{id}`
- `POST /v1/routines`
- `PUT /v1/routines/{id}`
- `DELETE /v1/routines/{id}`

### Workouts
- `GET /v1/workouts`
- `GET /v1/workouts/{sessionId}`
- `PATCH /v1/workouts/{sessionId}`
- `DELETE /v1/workouts/{sessionId}`
- `POST /v1/workouts`
- `POST /v1/workouts/{sessionId}/sets`
- `PATCH /v1/workouts/{sessionId}/sets/{setId}`
- `DELETE /v1/workouts/{sessionId}/sets/{setId}`
- `status` distingue `active` y `completed`; solo finalizar cambia la sesion a completada. `GET` admite filtro `status`.
- `client_session_id` es unico por usuario; `client_set_id` por sesion. Conservarlos en reintentos.
- `name` es una instantanea independiente del nombre de la rutina al iniciar (sin rutina: `Entrenamiento`); las lecturas antiguas sin nombre usan el de la rutina como fallback.
- `PATCH` permite editar `name` (trim, 1..255 caracteres), `date` (`YYYY-MM-DD` o datetime ISO con segundos y zona, normalizado a UTC) y `duration_seconds` (entero 0..604800), con o sin `status: completed`. El cierre exige al menos una serie completada, conserva las incompletas sin contarlas y nunca modifica la rutina. Repetir el cierre devuelve la sesion persistida sin reabrirla.
- `duration_minutes` sigue siendo compatible: al escribir minutos se calculan segundos; si se envian ambos prevalecen los segundos exactos y los minutos se obtienen truncando. El detalle y la lista incluyen ambos campos.
- Cada set admite `exercise_order` entero no negativo (por defecto 0) en POST/PATCH. El detalle y `include_sets` ordenan por `exercise_order`, despues `set_number` y por ID en empates.
- El detalle incluye `personal_records: [{type, exercise_id, exercise_name, set_id, weight, reps, value, previous_value}]`, con `type` en `max_weight|max_reps|max_e1rm`. Solo sesiones completadas, una mejor serie por ejercicio/tipo y comparacion con sesiones completadas anteriores (fecha, ID para desempatar); excluye warmups, incompletas y cargas no positivas. e1RM usa la regla existente de 1..12 reps. Sin baseline previo positivo o sin mejora devuelve `[]`, tambien para la primera sesion.
- Migraciones requeridas: `Version20261019000100` (estado/identidades; sesiones antiguas completadas solo con duracion y alguna serie completada) y `Version20261019000300` (nombre independiente, segundos exactos de sesion y orden de ejercicio). La nueva migracion copia el nombre de la rutina o `Entrenamiento`, convierte minutos antiguos a segundos y asigna orden 0 a los sets existentes. Ejecutar las migraciones antes de desplegar el nuevo cierre; no modificar migraciones previas.
- Tras desplegar entidades y migraciones, regenerar la cache del entorno (`php bin/console cache:clear --env=prod --no-debug`). Una cache antigua de metadatos puede omitir los nuevos campos al persistir aunque el controlador ya los serialice.

### Preferencias y registros auxiliares
- `GET/PUT /v1/user/preferences`: perfil energetico y objetivos; `roles` es solo lectura. Mifflin × actividad calcula mantenimiento; `calorie_adjustment` calcula ingesta. No sumar calorias del entrenamiento de nuevo.
- Lista de compra y ayuno permiten actualizar y borrar exclusivamente registros del usuario autenticado. El frontend solo confirma cambios persistidos.

## Reglas funcionales importantes
### Routines
- `daysOfWeek` acepta enteros `1..7` o tokens string usados por la app
- `exercises` debe validarse antes de persistir
- creacion y actualizacion deben ser atomicas
- no debe quedar una rutina creada a medias si fallan ejercicios

### Diet Plans
- `day_of_week` valido: `mon,tue,wed,thu,fri,sat,sun`
- no se deben permitir dias duplicados en un mismo plan
- `meal_type` valido: `breakfast,almuerzo,lunch,merienda,dinner,snack`
- `multiplier` debe ser mayor que `0` y menor o igual que `100`
- si un plan se marca `is_default`, debe desmarcar los demas del mismo usuario
- `apply` exige `confirm_consumed: true` y crea entradas para la semana lunes-domingo que contiene `start_date`; guardar un plan no modifica el diario.

### Measurements
- `weight_kg` obligatorio en create
- `weight_kg` valido: `> 0` y `<= 500`
- `body_fat_pct` valido: `0..100`
- medidas corporales validas: `0..500`
- `date` debe ser `YYYY-MM-DD` o un datetime ISO valido

### Foods
- `/v1/foods/{id}` debe devolver JSON `404`, no HTML
- la busqueda se hace sobre nombre o marca, en minusculas

### Errors
- `/v1/errors` y `/v1/errors/{id}` requieren ROLE_ADMIN.
- El listener guarda clase, ubicacion y frames sin argumentos; no persiste mensajes arbitrarios, URI sin sanear ni IP.
- Headers, query y body conservan estructura con valores redactados recursivamente; bodies no estructurados se omiten.
- Los errores internos de API devuelven JSON generico incluso si falla la persistencia del log.
- Nelmio es la unica autoridad CORS y usa la allowlist CORS_ALLOW_ORIGIN.

## Entidades clave
### User
- identificado por `firebaseUid`

### Routine
- pertenece a `User`
- tiene `daysOfWeek`
- tiene muchos `RoutineExercise`

### RoutineExercise
- une `Routine` con `Exercise`
- guarda `sets`, `reps`, `restSeconds`, `orderIndex`

### WorkoutSession
- pertenece a `User`
- puede apuntar a `Routine`
- tiene muchos `WorkoutSetLog`

### WorkoutSetLog
- pertenece a `WorkoutSession`
- apunta a `Exercise`

### Food
- tiene `externalId` unico
- tiene muchos `Serving`

### Serving
- macros y calorias se almacenan como `DECIMAL`
- en PHP se exponen como `float`, internamente Doctrine trabaja con strings

### MealDiary
- pertenece a `User`
- tiene muchos `MealEntry`
- recalcula `totalCalories`, `totalProteins`, `totalCarbs`, `totalFats`

### DietPlan
- pertenece a `User`
- tiene muchos `DietPlanDay`

### DietPlanDay
- pertenece a `DietPlan`
- tiene muchos `DietPlanMeal`

### DietPlanMeal
- apunta a `Serving`
- guarda `mealType`, `multiplier`, `optionGroup`, `notes`

## Convenciones y decisiones actuales
- los controladores devuelven JSON en todos los casos esperables
- la validacion esta mayoritariamente en controladores, no en DTOs ni Form Types
- varias lecturas siguen usando repositorio directo o SQL puntual
- las asociaciones principales se mantienen con metodos `add/remove`

## Tests actuales
Cobertura actual validada:
- auth basica
- diaries
- routines
- workouts
- exercises
- foods
- measurements
- diet plans
- error logs
- relaciones de entidades
- recalc de `MealDiary`
- tipo Doctrine custom para UUID texto


Comando:
```powershell
php -d memory_limit=512M vendor\bin\phpunit --no-coverage
```

## Comandos utiles
Ver rutas:
```powershell
php bin\console debug:router --no-ansi
```

Validar mapping:
```powershell
php bin\console doctrine:schema:validate --no-ansi
```

Volcar diferencia de esquema:
```powershell
php bin\console doctrine:schema:update --dump-sql --no-ansi
```

Consultar SQL manual:
```powershell
php bin\console doctrine:query:sql "SELECT COUNT(*) FROM exercises" --no-ansi
```

## Riesgos y deuda tecnica actual
### 1. Esquema no sincronizado al 100%
El mapping Doctrine esta correcto, pero `doctrine:schema:validate` todavia informa:
- `Database schema is not in sync with the current mapping file`

Eso no bloquea la suite funcional actual, pero significa que queda trabajo de migracion/alineacion de esquema.

### 2. Validacion en controladores
La API depende mucho de validacion manual dentro de controladores.
Si se amplian endpoints, conviene considerar DTOs o un patron de request validation mas consistente.

### 3. Acceso administrativo y diagnosticos de errores
`/v1/errors` esta restringido a administradores. No volver a persistir valores de peticiones, mensajes arbitrarios ni argumentos de stack: pueden contener credenciales o datos personales.

### 4. Mezcla de estilos
Hay controladores con QueryBuilder, otros con `find()`, otros con SQL directo.
Antes de refactors amplios, revisar cada caso para no reintroducir el problema de UUIDs.

## Reglas para futuras IAs
- no cambiar el manejo de UUIDs a binario
- no asumir PostgreSQL; el proyecto actual opera con MySQL
- no reintroducir inserts manuales para relaciones de rutinas si no hay transaccion completa
- si se tocan decimales Doctrine, recordar que DBAL devuelve strings para `decimal`
- mantener respuestas JSON consistentes en errores `400/401/403/404/500`
- ejecutar PHPUnit despues de cambios funcionales
- si se toca schema, revisar siempre `doctrine:schema:validate` y `doctrine:schema:update --dump-sql`

## Punto de partida recomendado para cualquier IA
1. Leer este archivo completo.
2. Revisar `config/packages/doctrine.yaml`.
3. Revisar `src/Controller` del dominio a tocar.
4. Revisar tests existentes del endpoint o entidad relacionada.
5. Ejecutar PHPUnit tras cualquier cambio funcional.
