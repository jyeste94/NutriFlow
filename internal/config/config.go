package config

import (
	"log"
	"os"
	"strconv"
	
	"github.com/joho/godotenv"
)

type Config struct {
	Port                 string
	LogLevel             string
	DatabaseURL          string
	FatSecretClientID     string
	FatSecretClientSecret string
	FatSecretScope        string
	FoodCacheTTLDays      int
	APIKey                string
}

func Load() (*Config, error) {
	// Load .env file if it exists
	if err := godotenv.Load(); err != nil {
		log.Println("No .env file found or error loading it")
	}

	return &Config{
		Port:                 getEnv("PORT", "8080"),
		LogLevel:             getEnv("LOG_LEVEL", "info"),
		DatabaseURL:          getEnv("DATABASE_URL", "postgres://postgres:postgres@localhost:5432/nutriflow?sslmode=disable"),
		FatSecretClientID:     getEnv("FATSECRET_CLIENT_ID", ""),
		FatSecretClientSecret: getEnv("FATSECRET_CLIENT_SECRET", ""),
		FatSecretScope:        getEnv("FATSECRET_SCOPE", "basic"),
		FoodCacheTTLDays:      getEnvAsInt("FOOD_CACHE_TTL_DAYS", 30),
		APIKey:                getEnv("NUTRIFLOW_API_KEY", ""),
	}, nil
}

func getEnv(key, fallback string) string {
	if value, exists := os.LookupEnv(key); exists {
		return value
	}
	return fallback
}

func getEnvAsInt(key string, fallback int) int {
	valueStr := getEnv(key, "")
	if valueStr == "" {
		return fallback
	}
	value, err := strconv.Atoi(valueStr)
	if err != nil {
		log.Printf("Invalid integer for %s: %s. Using default: %d", key, valueStr, fallback)
		return fallback
	}
	return value
}
