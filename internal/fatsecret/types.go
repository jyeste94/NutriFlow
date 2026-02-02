package fatsecret

import "encoding/json"

// Response wrappers
type SearchResponse struct {
	Foods struct {
		Food []FoodSummary `json:"food"`
		TotalResults string `json:"total_results"` // sometimes string "10"
	} `json:"foods"`
}

type FoodSummary struct {
	FoodID      string `json:"food_id"`
	FoodName    string `json:"food_name"`
	BrandName   string `json:"brand_name,omitempty"`
	FoodType    string `json:"food_type"`
	FoodDescription string `json:"food_description"` // Often contains calories details
}

type GetFoodResponse struct {
	Food FoodDetail `json:"food"`
}

type FoodDetail struct {
	FoodID   string `json:"food_id"`
	FoodName string `json:"food_name"`
	BrandName string `json:"brand_name,omitempty"`
	Servings struct {
		Serving ServingSlice `json:"serving"`
	} `json:"servings"`
}

type ServingSlice []ServingDetail

func (s *ServingSlice) UnmarshalJSON(data []byte) error {
	// 1. Try list
	var list []ServingDetail
	if err := json.Unmarshal(data, &list); err == nil {
		*s = list
		return nil
	}

	// 2. Try single object
	var single ServingDetail
	if err := json.Unmarshal(data, &single); err == nil {
		*s = []ServingDetail{single}
		return nil
	}

	return nil // Or error? Best to be safe and return nil if neither matches (e.g. empty)
}

type ServingDetail struct {
	ServingID           string `json:"serving_id"`
	ServingDescription  string `json:"serving_description"`
	MetricServingAmount string `json:"metric_serving_amount"`
	MetricServingUnit   string `json:"metric_serving_unit"`
	NumberOfUnits       string `json:"number_of_units"`
	MeasurementDescription string `json:"measurement_description"`
	Calories            string `json:"calories"`
	Carbohydrate        string `json:"carbohydrate"`
	Protein             string `json:"protein"`
	Fat                 string `json:"fat"`
}

type TokenResponse struct {
	AccessToken string `json:"access_token"`
	TokenType   string `json:"token_type"`
	ExpiresIn   int    `json:"expires_in"`
}
