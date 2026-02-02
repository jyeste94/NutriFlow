package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"nutriflow/internal/config"
	"nutriflow/internal/db"
	"nutriflow/internal/fatsecret"
	"nutriflow/internal/http/handlers"
	"nutriflow/internal/http/middleware"
	"nutriflow/internal/service"
)

func main() {
	// Initialize logger
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	slog.SetDefault(logger)

	// Load config
	cfg, err := config.Load()
	if err != nil {
		logger.Error("Failed to load config", "error", err)
		os.Exit(1)
	}

	logger.Info("Starting NutriFlow API", "port", cfg.Port)

	// Database
	ctx := context.Background()
	dbConn, err := db.Connect(ctx, cfg.DatabaseURL)
	if err != nil {
		logger.Error("Failed to connect to DB", "error", err)
		os.Exit(1)
	}
	defer dbConn.Close()

	repo := db.NewRepository(dbConn)

	// Run Migrations
	logger.Info("Running migrations...")
	if err := db.RunMigrations(cfg.DatabaseURL); err != nil {
		logger.Error("Migration failed", "error", err)
		os.Exit(1)
	}
	logger.Info("Migrations completed")

	// FatSecret
	fsClient := fatsecret.NewClient(cfg.FatSecretClientID, cfg.FatSecretClientSecret, cfg.FatSecretScope)

	// Service
	svc := service.NewFoodService(repo, fsClient, cfg)
	
	// Handler
	h := handlers.NewFoodHandler(svc)

	// Router
	mux := http.NewServeMux()
	
	// Middleware Chains
	baseMiddleware := func(h http.HandlerFunc) http.Handler {
		return middleware.RateLimit(middleware.Logger(middleware.Recoverer(http.HandlerFunc(h))))
	}

	// Protected Middleware Chain (Base + Auth)
	protect := func(h http.Handler) http.Handler {
		return middleware.APIKeyAuth(cfg.APIKey)(h)
	}

	mux.Handle("GET /health", baseMiddleware(h.Health))
	
	// Protected Routes
	mux.Handle("GET /v1/foods/search", protect(baseMiddleware(h.Search)))
	mux.Handle("GET /v1/foods/{foodId}", protect(baseMiddleware(h.GetFood)))
	mux.Handle("GET /v1/foods/barcode/{barcode}", protect(baseMiddleware(h.GetFoodByBarcode)))

	// Static Assets (Docs)
	// Open but rate-limited? Or fully public. Docs usually public.
	mux.Handle("GET /openapi.yaml", baseMiddleware(func(w http.ResponseWriter, r *http.Request) {
		http.ServeFile(w, r, "openapi.yaml")
	}))
	mux.Handle("GET /docs/", baseMiddleware(http.StripPrefix("/docs/", http.FileServer(http.Dir("docs"))).ServeHTTP))

	// Server
	srv := &http.Server{
		Addr:    ":" + cfg.Port,
		Handler: middleware.CORS(mux),
	}

	// Graceful shutdown
	go func() {
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Error("Server failed", "error", err)
			os.Exit(1)
		}
	}()

	logger.Info("Server started")

	c := make(chan os.Signal, 1)
	signal.Notify(c, os.Interrupt, syscall.SIGTERM)
	<-c

	logger.Info("Shutting down...")
	ctxShutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	
	if err := srv.Shutdown(ctxShutdown); err != nil {
		logger.Error("Server shutdown failed", "error", err)
	}
	logger.Info("Server exited")
}
