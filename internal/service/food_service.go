package service

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"nutriflow/internal/config"
	"nutriflow/internal/db"
	"nutriflow/internal/fatsecret"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
)


type FoodService struct {
	repo      db.RepositoryInterface
	fsClient  fatsecret.FatSecretClient
	config    *config.Config
}

func NewFoodService(repo db.RepositoryInterface, fsClient fatsecret.FatSecretClient, cfg *config.Config) *FoodService {
	return &FoodService{
		repo:     repo,
		fsClient: fsClient,
		config:   cfg,
	}
}

func (s *FoodService) Search(ctx context.Context, query string, page, limit int) ([]db.Food, error) {
	// 1. Search in DB
	// Just simple logic: if page=1, try API too if DB has few results.
	// For advanced usage, we might want to always mix, but let's stick to requirements.
	// "Primero buscar en BD... Si hay pocos resultados ... complementar".
	
	dbLimit := limit
	if dbLimit <= 0 {
		dbLimit = 50
	}

	dbResults, err := s.repo.SearchFoods(ctx, query, dbLimit)
	if err != nil {
		slog.Error("DB search failed", "error", err)
		// Don't fail completely, try API? Or fail? Let's generic fail for now or continue.
	}

	if len(dbResults) >= dbLimit/2 {
		return dbResults, nil
	}
	
	// 2. Call FatSecret
	// Only if page is 1 (simplification, as FatSecret paging is different)
	if page <= 1 {
		fsResp, err := s.fsClient.SearchFoods(query, 0) // page 0 in FS
		if err != nil {
			slog.Error("FatSecret search failed", "error", err)
			return dbResults, nil // Return what we have
		}

		// 3. Persist results
		// We only get summary from search. We usually need full details to persist useful servings?
		// Requirement: "persistir resultados". 
		// Note: FS Search only gives basics. If we want full servings, we might need to call GetFood for each.
		// BUT that's expensive (Rate Limits).
		// Compromise: Persist the Food Shell (Source, ExternalID, Name) and fetch details ON DEMAND (GetFood).
		// However, the prompt says "reemplazar servings asociados". If we don't have servings yet, we just store food.
		
		for _, fsFood := range fsResp.Foods.Food {
			// Convert to DB model
			f := &db.Food{
				ExternalSource: "fatsecret",
				ExternalID:     fsFood.FoodID,
				Name:           fsFood.FoodName,
				Brand:          &fsFood.BrandName,
				// Barcode and Servings are not in Search result usually.
			}
			if _, err := s.repo.UpsertFood(ctx, f); err != nil {
				slog.Error("Failed to cache food", "id", fsFood.FoodID, "error", err)
			}
		}
		
		// Re-query DB to include newly added items and get proper IDs
		// Or manually merge. Re-query is easier.
		return s.repo.SearchFoods(ctx, query, dbLimit)
	}

	return dbResults, nil
}

func (s *FoodService) GetFood(ctx context.Context, id uuid.UUID) (*db.Food, []db.Serving, error) {
	// 1. Check DB
	f, err := s.repo.GetFoodByID(ctx, id)
	if err != nil {
		return nil, nil, err
	}
	if f == nil {
		return nil, nil, errors.New("food not found in DB")
	}

	// 2. Check TTL
	ttl := time.Duration(s.config.FoodCacheTTLDays) * 24 * time.Hour
	if time.Since(f.LastFetchedAt) > ttl {
		// Refresh
		if err := s.refreshFood(ctx, f); err != nil {
			slog.Error("Failed to refresh food", "id", id, "error", err)
			// Proceed with stale data
		} else {
			// Reload
			f, _ = s.repo.GetFoodByID(ctx, id)
		}
	}

	// 3. Get Servings
	servings, err := s.repo.GetServings(ctx, f.ID)
	// If no servings (e.g. from search-only cache), try force refresh
	if len(servings) == 0 {
		if err := s.refreshFood(ctx, f); err == nil {
			servings, _ = s.repo.GetServings(ctx, f.ID)
		}
	}

	return f, servings, err
}

func (s *FoodService) GetFoodByBarcode(ctx context.Context, barcode string) (*db.Food, []db.Serving, error) {
	// 1. Check DB
	f, err := s.repo.GetFoodByBarcode(ctx, barcode)
	if err != nil {
		return nil, nil, err
	}
	
	if f != nil {
		// Check TTL
		ttl := time.Duration(s.config.FoodCacheTTLDays) * 24 * time.Hour
		if time.Since(f.LastFetchedAt) > ttl {
			s.refreshFood(ctx, f) // update via ID if we have external ID
			f, _ = s.repo.GetFoodByBarcode(ctx, barcode)
		}
	} else {
		// 2. Fallback to FatSecret
		externalID, err := s.fsClient.FindIDForBarcode(barcode)
		if err != nil {
			return nil, nil, fmt.Errorf("barcode not found in FatSecret: %w", err)
		}
		
		// We have external ID, let's fetch full details (which handles persistence/upsert)
		// We can reuse refreshFood logic but we need a dummy food object first or just call GetFood directly?
		// Better: call GetFood with externalID directly via service logic?
		// We don't have a public "GetFoodByExternalID" in service yet (only by UUID).
		// But refreshFood takes a *db.Food.
		
		// Let's check if this external ID is already in our DB (maybe under different barcode or manual insert?)
		f, err = s.repo.GetFoodByExternalID(ctx, "fatsecret", externalID)
		if err != nil {
			return nil, nil, err
		}
		
		if f == nil {
			// Create new skeleton
			f = &db.Food{
				ExternalSource: "fatsecret",
				ExternalID:     externalID,
				Barcode:        &barcode, // Set the barcode we searched for
			}
			// Upsert to get a UUID
			// check fetch logic
		} else {
			// Update barcode if missing
			if f.Barcode == nil {
				f.Barcode = &barcode
			}
		}
		
		// Now refresh (fetching details and saving servings)
		if err := s.refreshFood(ctx, f); err != nil {
			return nil, nil, err
		}
		
		// Reload fresh
		f, _ = s.repo.GetFoodByID(ctx, f.ID)
	}

	servings, err := s.repo.GetServings(ctx, f.ID)
	return f, servings, err
}


func (s *FoodService) refreshFood(ctx context.Context, f *db.Food) error {
	if f.ExternalSource != "fatsecret" {
		return nil // Can't refresh manual items
	}

	fsDetails, err := s.fsClient.GetFood(f.ExternalID)
	if err != nil {
		return err
	}

	// Update Food
	f.Name = fsDetails.Food.FoodName
	if fsDetails.Food.BrandName != "" {
		b := fsDetails.Food.BrandName
		f.Brand = &b
	}
	
	// Persist
	_, err = s.repo.UpsertFood(ctx, f)
	if err != nil {
		return err
	}

	// Process Servings
	var newServings []db.Serving
	for _, srv := range fsDetails.Food.Servings.Serving {
		amount, _ := strconv.ParseFloat(srv.MetricServingAmount, 64)
		cal, _ := strconv.ParseFloat(srv.Calories, 64)
		prot, _ := strconv.ParseFloat(srv.Protein, 64)
		carb, _ := strconv.ParseFloat(srv.Carbohydrate, 64)
		fat, _ := strconv.ParseFloat(srv.Fat, 64)

		newServings = append(newServings, db.Serving{
			Description:  srv.ServingDescription,
			MetricAmount: amount,
			MetricUnit:   srv.MetricServingUnit,
			Calories:     cal,
			ProteinG:     prot,
			CarbsG:       carb,
			FatG:         fat,
		})
	}

	if err := s.repo.ReplaceServings(ctx, f.ID, newServings); err != nil {
		return err
	}
	
	// Best Serving Logic
	bestID := s.calculateBestServing(newServings)
	if bestID != uuid.Nil {
		// We need the ACTUAL inserted UUIDs.
		// Replacing servings deletes entries and creates new ones, so IDs change.
		// My logic above doesn't get IDs back easily unless I insert one by one or fetch back.
		// Optimisation: Fetch back servings after insert to find the matching one?
		// Or: change ReplaceServings to return IDs.
		// Let's refetch servings from DB to be safe and match by description/amount.
		
		savedServings, _ := s.repo.GetServings(ctx, f.ID)
		bestID = s.calculateBestServing(savedServings)
		if bestID != uuid.Nil {
			s.repo.UpdateBestServing(ctx, f.ID, bestID)
		}
	}

	return nil
}

func (s *FoodService) calculateBestServing(servings []db.Serving) uuid.UUID {
	if len(servings) == 0 {
		return uuid.Nil
	}
	
	// Rule: "preferir serving con “100 g” o metric_amount=100 y metric_unit=g; si no, el primero."
	for _, srv := range servings {
		if srv.MetricAmount == 100 && strings.EqualFold(srv.MetricUnit, "g") {
			return srv.ID
		}
		if strings.Contains(srv.Description, "100 g") { // Simple check
			return srv.ID
		}
	}
	
	return servings[0].ID
}
