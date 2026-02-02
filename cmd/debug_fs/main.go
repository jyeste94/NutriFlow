package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"nutriflow/internal/fatsecret"

	"github.com/joho/godotenv"
)

// Minimal Client to just dump raw JSON
func main() {
	_ = godotenv.Load()

	clientID := os.Getenv("FATSECRET_CLIENT_ID")
	clientSecret := os.Getenv("FATSECRET_CLIENT_SECRET")
	scope := "basic" // or whatever is in .env, typically "basic"

	if clientID == "" || clientSecret == "" {
		fmt.Println("Missing credentials in .env")
		return
	}

	// 1. Get Token
	tokenURL := "https://oauth.fatsecret.com/connect/token"
	data := url.Values{}
	data.Set("grant_type", "client_credentials")
	data.Set("scope", scope)
	data.Set("client_id", clientID)
	data.Set("client_secret", clientSecret)

	req, _ := http.NewRequest("POST", tokenURL, strings.NewReader(data.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != 200 {
		body, _ := io.ReadAll(resp.Body)
		panic(fmt.Sprintf("Auth failed: %s", string(body)))
	}

	var tokenResp struct {
		AccessToken string `json:"access_token"`
	}
	json.NewDecoder(resp.Body).Decode(&tokenResp)
	token := tokenResp.AccessToken

	// 2. Get Food 5000002
	foodID := "5000002" // Strawberry Banana Fruit Shake
	apiURL := "https://platform.fatsecret.com/rest/food/v2"
	
	params := url.Values{}
	params.Set("method", "food.get.v2")
	params.Set("food_id", foodID)
	params.Set("format", "json")

	// REST API usually: GET url?params with Bearer token
	fullURL := fmt.Sprintf("%s?%s", apiURL, params.Encode())
	
	req, _ = http.NewRequest("GET", fullURL, nil)
	req.Header.Set("Authorization", "Bearer "+token)

	resp, err = http.DefaultClient.Do(req)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	os.WriteFile("debug_output.json", body, 0644)
	// Test Unmarshal
	var fsResp fatsecret.GetFoodResponse
	if err := json.Unmarshal(body, &fsResp); err != nil {
		fmt.Printf("Unmarshal failed: %v\n", err)
	} else {
		fmt.Printf("Unmarshal Success! Found %d servings.\n", len(fsResp.Food.Servings.Serving))
		for i, s := range fsResp.Food.Servings.Serving {
			fmt.Printf(" - Serving %d: %s (%s %s)\n", i, s.ServingDescription, s.MetricServingAmount, s.MetricServingUnit)
		}
	}
}
