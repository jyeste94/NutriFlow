package service

import (
	"context"
	"testing"

	"nutriflow/internal/config"
	"nutriflow/internal/db"
	"nutriflow/internal/fatsecret"

	"github.com/google/uuid"
)

// Mocks

type MockRepo struct {
	SearchFoodsFunc func(ctx context.Context, query string, limit int) ([]db.Food, error)
	UpsertFoodFunc  func(ctx context.Context, f *db.Food) (uuid.UUID, error)
	// Add others as needed
}

// Satisfy Interface (incomplete mocks just for what we need locally or fully?)
// We need to implement all methods of RepositoryInterface.
func (m *MockRepo) SearchFoods(ctx context.Context, query string, limit int) ([]db.Food, error) {
	if m.SearchFoodsFunc != nil {
		return m.SearchFoodsFunc(ctx, query, limit)
	}
	return nil, nil
}
func (m *MockRepo) UpsertFood(ctx context.Context, f *db.Food) (uuid.UUID, error) {
	if m.UpsertFoodFunc != nil {
		return m.UpsertFoodFunc(ctx, f)
	}
	return uuid.Nil, nil
}
func (m *MockRepo) GetFoodByID(ctx context.Context, id uuid.UUID) (*db.Food, error) { return nil, nil }
func (m *MockRepo) GetFoodByBarcode(ctx context.Context, barcode string) (*db.Food, error) { return nil, nil }
func (m *MockRepo) GetFoodByExternalID(ctx context.Context, source, extID string) (*db.Food, error) { return nil, nil }
func (m *MockRepo) ReplaceServings(ctx context.Context, foodID uuid.UUID, servings []db.Serving) error { return nil }
func (m *MockRepo) GetServings(ctx context.Context, foodID uuid.UUID) ([]db.Serving, error) { return nil, nil }
func (m *MockRepo) UpdateBestServing(ctx context.Context, foodID, servingID uuid.UUID) error { return nil }

type MockClient struct {
	SearchFoodsFunc func(query string, page int) (*fatsecret.SearchResponse, error)
}

func (m *MockClient) SearchFoods(query string, page int) (*fatsecret.SearchResponse, error) {
	if m.SearchFoodsFunc != nil {
		return m.SearchFoodsFunc(query, page)
	}
	return nil, nil
}
func (m *MockClient) GetFood(foodID string) (*fatsecret.GetFoodResponse, error) { return nil, nil }
func (m *MockClient) FindIDForBarcode(barcode string) (string, error) { return "", nil }

// Tests

func TestSearch_CacheHit(t *testing.T) {
	mockRepo := &MockRepo{}
	mockClient := &MockClient{}
	cfg := &config.Config{}
	svc := NewFoodService(mockRepo, mockClient, cfg)

	// DB returns enough results
	mockRepo.SearchFoodsFunc = func(ctx context.Context, query string, limit int) ([]db.Food, error) {
		return []db.Food{{Name: "Cached Apple"}}, nil
	}
	
	// API should NOT be called
	mockClient.SearchFoodsFunc = func(query string, page int) (*fatsecret.SearchResponse, error) {
		t.Error("FatSecret API should not be called when DB has results")
		return nil, nil
	}

	results, err := svc.Search(context.Background(), "apple", 1, 2)
	if err != nil {
		t.Fatalf("Unexpected error: %v", err)
	}
	if len(results) != 1 || results[0].Name != "Cached Apple" {
		t.Errorf("Unexpected results: %+v", results)
	}
}

func TestSearch_CacheMiss_Fallback(t *testing.T) {
	mockRepo := &MockRepo{}
	mockClient := &MockClient{}
	cfg := &config.Config{}
	svc := NewFoodService(mockRepo, mockClient, cfg)

	// DB empty
	mockRepo.SearchFoodsFunc = func(ctx context.Context, query string, limit int) ([]db.Food, error) {
		return []db.Food{}, nil
	}

	// API returns results
	mockClient.SearchFoodsFunc = func(query string, page int) (*fatsecret.SearchResponse, error) {
		return &fatsecret.SearchResponse{
			Foods: struct {
				Food []fatsecret.FoodSummary `json:"food"` 
				TotalResults string `json:"total_results"`
			}{
				Food: []fatsecret.FoodSummary{
					{FoodID: "100", FoodName: "Fresh Apple", FoodType: "Brand", FoodDescription: "Desc"},
				},
			},
		}, nil
	}
	
	// Expect Upsert
	upsertCalled := false
	mockRepo.UpsertFoodFunc = func(ctx context.Context, f *db.Food) (uuid.UUID, error) {
		upsertCalled = true
		if f.ExternalID != "100" {
			t.Errorf("Upserting wrong ID: %s", f.ExternalID)
		}
		return uuid.New(), nil
	}

	svc.Search(context.Background(), "apple", 1, 50)
	
	if !upsertCalled {
		t.Error("UpsertFood should have been called")
	}
}
