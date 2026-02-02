# NutriFlow API

Backend API in Go integrating FatSecret Platform API with local caching and persistence in PostgreSQL.

## Features

- **Hybrid Search**: Searches local DB first, then falls back to FatSecret API.
- **Persistence**: Caches food and serving details to build a proprietary catalog.
- **Background Refresh**: Automatically refreshes stale data (TTL > 30 days).
- **API**: RESTful endpoints with OpenAPI 3.0 specification.
- **Infrastructure**: Dockerized setup with PostgreSQL and Migrations.

## Setup

### Prerequisites
- Go 1.22+
- Docker & Docker Compose
- FatSecret API Credentials

### Installation

1. Clone the repository.
2. Copy `.env.example` to `.env` and fill in your FatSecret credentials:
   ```bash
   cp .env.example .env
   ```
3. Start the infrastructure:
   ```bash
   docker-compose up -d
   ```
   This will start PostgreSQL and the API server. Migrations are not automatically run in the Dockerfile yet (TODO: Add migration entrypoint), so run them manually or enable them in app.

   **Manual Migration (Development):**
   ```bash
   # Install golang-migrate
   migrate -path migrations -database "postgres://postgres:postgres@localhost:5432/nutriflow?sslmode=disable" up
   ```

### Usage

**Search Foods:**
```bash
curl "http://localhost:8080/v1/foods/search?q=apple"
```

**Get Food Details:**
```bash
curl "http://localhost:8080/v1/foods/{uuid}"
```

**Get Food by Barcode:**
```bash
curl "http://localhost:8080/v1/foods/barcode/{barcode}"
```

## Architecture

- **Hexagonal / Clean Architecture-ish**:
  - `cmd`: Entry points.
  - `internal/domain`: Core interfaces and entities.
  - `internal/service`: Business logic (Caching, API coordination).
  - `internal/db`: Data access (PostgreSQL with pgx).
  - `internal/fatsecret`: External adapter.
  - `internal/http`: Transport layer.

## Development

Run locally:
```bash
go run cmd/server/main.go
```
