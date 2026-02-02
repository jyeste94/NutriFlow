package handlers

import (
	"encoding/json"
	"net/http"
	"strconv"

	"nutriflow/internal/db"
	"nutriflow/internal/domain"

	"github.com/google/uuid"
)

type FoodHandler struct {
	ResultService domain.FoodService
}

func NewFoodHandler(s domain.FoodService) *FoodHandler {
	return &FoodHandler{ResultService: s}
}

func (h *FoodHandler) Health(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
	w.Write([]byte("OK"))
}

func (h *FoodHandler) Search(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query().Get("q")
	if q == "" {
		http.Error(w, "query parameter q is required", http.StatusBadRequest)
		return
	}

	page, _ := strconv.Atoi(r.URL.Query().Get("page"))
	if page < 1 {
		page = 1
	}

	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	if limit < 1 {
		limit = 50
	}

	foods, err := h.ResultService.Search(r.Context(), q, page, limit)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}

	respondJSON(w, foods)
}

func (h *FoodHandler) GetFood(w http.ResponseWriter, r *http.Request) {
	idStr := r.PathValue("foodId") // Go 1.22+
	id, err := uuid.Parse(idStr)
	if err != nil {
		http.Error(w, "invalid uuid", http.StatusBadRequest)
		return
	}

	food, servings, err := h.ResultService.GetFood(r.Context(), id)
	if err != nil {
		http.Error(w, err.Error(), http.StatusNotFound) 
		return
	}

	respondWithDetails(w, food, servings)
}

func (h *FoodHandler) GetFoodByBarcode(w http.ResponseWriter, r *http.Request) {
	barcode := r.PathValue("barcode")
	if barcode == "" {
		http.Error(w, "barcode is required", http.StatusBadRequest)
		return
	}

	food, servings, err := h.ResultService.GetFoodByBarcode(r.Context(), barcode)
	if err != nil {
		http.Error(w, err.Error(), http.StatusNotFound)
		return
	}

	respondWithDetails(w, food, servings)
}

func respondJSON(w http.ResponseWriter, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(data)
}

func respondWithDetails(w http.ResponseWriter, f *db.Food, s []db.Serving) {
	type Response struct {
		*db.Food
		Servings []db.Serving `json:"servings"`
	}
	respondJSON(w, Response{Food: f, Servings: s})
}
