package handlers

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"

	"nutriflow/internal/db"
	"nutriflow/internal/domain"

	"github.com/google/uuid"
)

// Manual Mock
type MockService struct {
	SearchFunc           func(ctx context.Context, query string, page, limit int) ([]db.Food, error)
	GetFoodFunc          func(ctx context.Context, id uuid.UUID) (*db.Food, []db.Serving, error)
	GetFoodByBarcodeFunc func(ctx context.Context, barcode string) (*db.Food, []db.Serving, error)
}

func (m *MockService) Search(ctx context.Context, query string, page, limit int) ([]db.Food, error) {
	if m.SearchFunc != nil {
		return m.SearchFunc(ctx, query, page, limit)
	}
	return nil, nil
}

func (m *MockService) GetFood(ctx context.Context, id uuid.UUID) (*db.Food, []db.Serving, error) {
	if m.GetFoodFunc != nil {
		return m.GetFoodFunc(ctx, id)
	}
	return nil, nil, nil
}

func (m *MockService) GetFoodByBarcode(ctx context.Context, barcode string) (*db.Food, []db.Serving, error) {
	if m.GetFoodByBarcodeFunc != nil {
		return m.GetFoodByBarcodeFunc(ctx, barcode)
	}
	return nil, nil, nil
}

// Ensure Mock satisfies interface
var _ domain.FoodService = &MockService{}

func TestSearch(t *testing.T) {
	// Setup
	mockSvc := &MockService{}
	h := NewFoodHandler(mockSvc)

	// Case 1: Missing Query
	req := httptest.NewRequest("GET", "/v1/foods/search", nil)
	w := httptest.NewRecorder()
	h.Search(w, req)

	if w.Code != http.StatusBadRequest {
		t.Errorf("Expected 400 Bad Request, got %d", w.Code)
	}

	// Case 2: Successful Search
	mockSvc.SearchFunc = func(ctx context.Context, query string, page, limit int) ([]db.Food, error) {
		return []db.Food{
			{Name: "Test Apple", ExternalID: "123"},
		}, nil
	}

	req = httptest.NewRequest("GET", "/v1/foods/search?q=apple", nil)
	w = httptest.NewRecorder()
	h.Search(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("Expected 200 OK, got %d", w.Code)
	}
	
	var res []db.Food
	if err := json.NewDecoder(w.Body).Decode(&res); err != nil {
		t.Fatal(err)
	}
	if len(res) != 1 || res[0].Name != "Test Apple" {
		t.Errorf("Unexpected response: %+v", res)
	}
}

func TestGetFood(t *testing.T) {
	mockSvc := &MockService{}
	h := NewFoodHandler(mockSvc)
	
	testID := uuid.New()

	// Case 1: Found
	mockSvc.GetFoodFunc = func(ctx context.Context, id uuid.UUID) (*db.Food, []db.Serving, error) {
		if id == testID {
			return &db.Food{ID: testID, Name: "Found Food"}, []db.Serving{}, nil
		}
		return nil, nil, errors.New("not found")
	}

	req := httptest.NewRequest("GET", "/v1/foods/"+testID.String(), nil)
	req.SetPathValue("foodId", testID.String()) // Simulate Go 1.22 routing
	w := httptest.NewRecorder()
	h.GetFood(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("Expected 200 OK, got %d", w.Code)
	}

	// Case 2: Invalid UUID
	req = httptest.NewRequest("GET", "/v1/foods/invalid", nil)
	req.SetPathValue("foodId", "invalid")
	w = httptest.NewRecorder()
	h.GetFood(w, req)
	
	if w.Code != http.StatusBadRequest {
		t.Errorf("Expected 400 for invalid uuid, got %d", w.Code)
	}
}
