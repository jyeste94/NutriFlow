# Build Stage
FROM golang:1.24-alpine AS builder

WORKDIR /app

# Dependencies
COPY go.mod go.sum ./
RUN go mod download

# Source
COPY . .

# Build
# CGO_ENABLED=0 for static binary
RUN CGO_ENABLED=0 GOOS=linux go build -o /app/nutriflow ./cmd/server

# Run Stage
FROM gcr.io/distroless/static-debian12

WORKDIR /app

# Copy binary
COPY --from=builder /app/nutriflow .
# Copy migrations for startup run
COPY --from=builder /app/migrations ./migrations
# Copy docs
COPY --from=builder /app/docs ./docs
COPY --from=builder /app/openapi.yaml .

# Expose port
EXPOSE 8080

# Environment variables should be injected at runtime
CMD ["./nutriflow"]
