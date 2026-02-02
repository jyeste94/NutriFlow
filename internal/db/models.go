package db

import (
	"time"

	"github.com/google/uuid"
)

type Food struct {
	ID            uuid.UUID  `json:"id"`
	ExternalSource string     `json:"external_source"`
	ExternalID    string     `json:"external_id"`
	Name          string     `json:"name"`
	Brand         *string    `json:"brand"`
	Barcode       *string    `json:"barcode"`
	BestServingID *uuid.UUID `json:"best_serving_id"`
	LastFetchedAt time.Time  `json:"last_fetched_at"`
	CreatedAt     time.Time  `json:"created_at"`
	UpdatedAt     time.Time  `json:"updated_at"`
}

type Serving struct {
	ID           uuid.UUID `json:"id"`
	FoodID       uuid.UUID `json:"food_id"`
	Description  string    `json:"description"`
	MetricAmount float64   `json:"metric_amount"`
	MetricUnit   string    `json:"metric_unit"`
	Calories     float64   `json:"calories"`
	ProteinG     float64   `json:"protein_g"`
	CarbsG       float64   `json:"carbs_g"`
	FatG         float64   `json:"fat_g"`
	CreatedAt    time.Time `json:"created_at"`
	UpdatedAt    time.Time `json:"updated_at"`
}
