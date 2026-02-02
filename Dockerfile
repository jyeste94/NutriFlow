# Build stage
FROM golang:1.22-alpine AS builder

WORKDIR /app

COPY go.mod go.sum ./
RUN go mod download

COPY . .

RUN CGO_ENABLED=0 GOOS=linux go build -o nutriflow-api ./cmd/server

# Final stage
FROM alpine:3.19

WORKDIR /app

COPY --from=builder /app/nutriflow-api .
COPY --from=builder /app/migrations ./migrations
COPY --from=builder /app/.env.example .

EXPOSE 8080

CMD ["./nutriflow-api"]
