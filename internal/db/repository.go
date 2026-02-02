package db

import (
	"context"
	"fmt"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
)

type RepositoryInterface interface {
	SearchFoods(ctx context.Context, query string, limit int) ([]Food, error)
	GetFoodByID(ctx context.Context, id uuid.UUID) (*Food, error)
	GetFoodByBarcode(ctx context.Context, barcode string) (*Food, error)
	GetFoodByExternalID(ctx context.Context, source, extID string) (*Food, error)
	UpsertFood(ctx context.Context, f *Food) (uuid.UUID, error)
	ReplaceServings(ctx context.Context, foodID uuid.UUID, servings []Serving) error
	GetServings(ctx context.Context, foodID uuid.UUID) ([]Serving, error)
	UpdateBestServing(ctx context.Context, foodID, servingID uuid.UUID) error
}

type Repository struct {
	db *DB
}

func NewRepository(db *DB) *Repository {
	return &Repository{db: db}
}

// Ensure food is updated (Upsert)
func (r *Repository) UpsertFood(ctx context.Context, f *Food) (uuid.UUID, error) {
	query := `
		INSERT INTO foods (external_source, external_id, name, brand, barcode, last_fetched_at, updated_at)
		VALUES ($1, $2, $3, $4, $5, NOW(), NOW())
		ON CONFLICT (external_source, external_id)
		DO UPDATE SET
			name = EXCLUDED.name,
			brand = EXCLUDED.brand,
			barcode = EXCLUDED.barcode,
			last_fetched_at = NOW(),
			updated_at = NOW()
		RETURNING id
	`
	var id uuid.UUID
	err := r.db.Pool.QueryRow(ctx, query,
		f.ExternalSource, f.ExternalID, f.Name, f.Brand, f.Barcode,
	).Scan(&id)
	if err != nil {
		return uuid.Nil, fmt.Errorf("failed to upsert food: %w", err)
	}
	f.ID = id
	return id, nil
}

// Replace servings for a food
func (r *Repository) ReplaceServings(ctx context.Context, foodID uuid.UUID, servings []Serving) error {
	tx, err := r.db.Pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx)

	// Delete existing
	_, err = tx.Exec(ctx, "DELETE FROM servings WHERE food_id = $1", foodID)
	if err != nil {
		return fmt.Errorf("failed to delete servings: %w", err)
	}

	// Insert new
	query := `
		INSERT INTO servings (food_id, description, metric_amount, metric_unit, calories, protein_g, carbs_g, fat_g)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
	`
	for _, s := range servings {
		_, err := tx.Exec(ctx, query,
			foodID, s.Description, s.MetricAmount, s.MetricUnit,
			s.Calories, s.ProteinG, s.CarbsG, s.FatG,
		)
		if err != nil {
			return fmt.Errorf("failed to insert serving: %w", err)
		}
	}

	return tx.Commit(ctx)
}

func (r *Repository) UpdateBestServing(ctx context.Context, foodID, servingID uuid.UUID) error {
	_, err := r.db.Pool.Exec(ctx, "UPDATE foods SET best_serving_id = $1 WHERE id = $2", servingID, foodID)
	return err
}

func (r *Repository) GetFoodByExternalID(ctx context.Context, source, extID string) (*Food, error) {
	query := `SELECT id, external_source, external_id, name, brand, barcode, best_serving_id, last_fetched_at FROM foods WHERE external_source=$1 AND external_id=$2`
	var f Food
	err := r.db.Pool.QueryRow(ctx, query, source, extID).Scan(
		&f.ID, &f.ExternalSource, &f.ExternalID, &f.Name, &f.Brand, &f.Barcode, &f.BestServingID, &f.LastFetchedAt,
	)
	if err != nil {
		if err == pgx.ErrNoRows {
			return nil, nil // Not found
		}
		return nil, err
	}
	return &f, nil
}

func (r *Repository) GetFoodByID(ctx context.Context, id uuid.UUID) (*Food, error) {
	query := `SELECT id, external_source, external_id, name, brand, barcode, best_serving_id, last_fetched_at FROM foods WHERE id=$1`
	var f Food
	err := r.db.Pool.QueryRow(ctx, query, id).Scan(
		&f.ID, &f.ExternalSource, &f.ExternalID, &f.Name, &f.Brand, &f.Barcode, &f.BestServingID, &f.LastFetchedAt,
	)
	if err != nil {
		if err == pgx.ErrNoRows {
			return nil, nil // Not found
		}
		return nil, err
	}
	return &f, nil
}

func (r *Repository) GetFoodByBarcode(ctx context.Context, barcode string) (*Food, error) {
	query := `SELECT id, external_source, external_id, name, brand, barcode, best_serving_id, last_fetched_at FROM foods WHERE barcode=$1`
	var f Food
	err := r.db.Pool.QueryRow(ctx, query, barcode).Scan(
		&f.ID, &f.ExternalSource, &f.ExternalID, &f.Name, &f.Brand, &f.Barcode, &f.BestServingID, &f.LastFetchedAt,
	)
	if err != nil {
		if err == pgx.ErrNoRows {
			return nil, nil
		}
		return nil, err
	}
	return &f, nil
}

func (r *Repository) SearchFoods(ctx context.Context, query string, limit int) ([]Food, error) {
	// Full text search + ILIKE fallback for short queries if needed, but Trigram is better.
	// We'll use simple ILIKE for now as Trigram setup needs more complex 'WHERE name % query' possibly.
	// But let's assume 'ILIKE' is safe enough for basic needs. With gin_trgm_ops index, ILIKE '%...%' works well.
	
	sql := `
		SELECT id, external_source, external_id, name, brand, barcode, best_serving_id, last_fetched_at 
		FROM foods 
		WHERE name ILIKE '%' || $1 || '%' 
		ORDER BY last_fetched_at DESC 
		LIMIT $2`
	
	rows, err := r.db.Pool.Query(ctx, sql, query, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	foods := []Food{}
	for rows.Next() {
		var f Food
		if err := rows.Scan(&f.ID, &f.ExternalSource, &f.ExternalID, &f.Name, &f.Brand, &f.Barcode, &f.BestServingID, &f.LastFetchedAt); err != nil {
			return nil, err
		}
		foods = append(foods, f)
	}
	return foods, nil
}

func (r *Repository) GetServings(ctx context.Context, foodID uuid.UUID) ([]Serving, error) {
	query := `SELECT id, food_id, description, metric_amount, metric_unit, calories, protein_g, carbs_g, fat_g FROM servings WHERE food_id=$1`
	rows, err := r.db.Pool.Query(ctx, query, foodID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	servings := []Serving{}
	for rows.Next() {
		var s Serving
		if err := rows.Scan(&s.ID, &s.FoodID, &s.Description, &s.MetricAmount, &s.MetricUnit, &s.Calories, &s.ProteinG, &s.CarbsG, &s.FatG); err != nil {
			return nil, err
		}
		servings = append(servings, s)
	}
	return servings, nil
}
