package domain

import (
	"context"
	"nutriflow/internal/db"

	"github.com/google/uuid"
)

// FoodService defines the business logic contract
type FoodService interface {
	Search(ctx context.Context, query string, page, limit int) ([]db.Food, error)
	GetFood(ctx context.Context, id uuid.UUID) (*db.Food, []db.Serving, error)
	GetFoodByBarcode(ctx context.Context, barcode string) (*db.Food, []db.Serving, error)
}
