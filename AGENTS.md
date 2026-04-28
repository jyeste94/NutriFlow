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

### Foods
- `GET /v1/foods/search`
- `GET /v1/foods/{id}`

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
- `apply` crea entradas de diario para la semana del `start_date`

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
- el listener de excepciones guarda contexto del request
- headers sensibles y algunos campos del body se redactan

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

Ultimo estado verificado:
- `34` tests
- `171` assertions
- PHPUnit en verde

Comando:
```powershell
vendor\bin\phpunit --no-coverage
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

### 3. Error logs accesibles para cualquier usuario autenticado
`/v1/errors` no esta restringido a admins. Hoy es una decision tecnica provisional.

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
