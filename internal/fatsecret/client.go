package fatsecret

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

const (
	TokenEndpoint = "https://oauth.fatsecret.com/connect/token"
	BaseURL       = "https://platform.fatsecret.com/rest"
)

type FatSecretClient interface {
	SearchFoods(query string, page int) (*SearchResponse, error)
	GetFood(foodID string) (*GetFoodResponse, error)
	FindIDForBarcode(barcode string) (string, error)
}

type Client struct {
	ClientID     string
	ClientSecret string
	Scope        string
	
	httpClient   *http.Client
	
	tokenMu      sync.Mutex
	accessToken  string
	tokenExpires time.Time
}

func NewClient(clientID, clientSecret, scope string) *Client {
	return &Client{
		ClientID:     clientID,
		ClientSecret: clientSecret,
		Scope:        scope,
		httpClient:   &http.Client{Timeout: 10 * time.Second},
	}
}

func (c *Client) getToken() (string, error) {
	c.tokenMu.Lock()
	defer c.tokenMu.Unlock()

	if c.accessToken != "" && time.Now().Before(c.tokenExpires) {
		return c.accessToken, nil
	}

	data := url.Values{}
	data.Set("grant_type", "client_credentials")
	data.Set("scope", c.Scope)

	req, err := http.NewRequest("POST", TokenEndpoint, strings.NewReader(data.Encode()))
	if err != nil {
		return "", err
	}

	auth := base64.StdEncoding.EncodeToString([]byte(fmt.Sprintf("%s:%s", c.ClientID, c.ClientSecret)))
	req.Header.Set("Authorization", "Basic "+auth)
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("failed to get token: %s - %s", resp.Status, string(body))
	}

	var parsed TokenResponse
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
		return "", err
	}

	c.accessToken = parsed.AccessToken
	c.tokenExpires = time.Now().Add(time.Duration(parsed.ExpiresIn-60) * time.Second)
	
	slog.Info("Refreshed FatSecret access token")
	
	return c.accessToken, nil
}

func (c *Client) doRequest(endpoint string, params url.Values, target interface{}) error {
	token, err := c.getToken()
	if err != nil {
		return err
	}

	// RESTful style: GET /rest/<endpoint>?params
	params.Set("format", "json")
	
	fullURL := fmt.Sprintf("%s/%s?%s", BaseURL, endpoint, params.Encode())
	
	req, err := http.NewRequest("GET", fullURL, nil)
	if err != nil {
		return err
	}
	
	req.Header.Set("Authorization", "Bearer "+token)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("API error: %s - %s", resp.Status, string(body))
	}

	return json.NewDecoder(resp.Body).Decode(target)
}

func (c *Client) SearchFoods(query string, page int) (*SearchResponse, error) {
	params := url.Values{}
	params.Set("search_expression", query)
	params.Set("page_number", fmt.Sprintf("%d", page))
	params.Set("max_results", "50")

	// Using v1 as seen in Postman for basic search (or v4 if premier, but safer to start with v1)
	var result SearchResponse
	if err := c.doRequest("foods/search/v1", params, &result); err != nil {
		return nil, err
	}
	return &result, nil
}

func (c *Client) GetFood(foodID string) (*GetFoodResponse, error) {
	params := url.Values{}
	params.Set("food_id", foodID)

	// food/v2 per Postman (Get by id v2)
	var result GetFoodResponse
	if err := c.doRequest("food/v2", params, &result); err != nil {
		return nil, err
	}
	return &result, nil
}

type BarcodeResponse struct {
	FoodID struct {
		Value string `json:"value"`
	} `json:"food_id"`
}

func (c *Client) FindIDForBarcode(barcode string) (string, error) {
	params := url.Values{}
	params.Set("barcode", barcode)

	// food/barcode/find-by-id/v1
	var result BarcodeResponse
	if err := c.doRequest("food/barcode/find-by-id/v1", params, &result); err != nil {
		return "", err
	}
	if result.FoodID.Value == "" || result.FoodID.Value == "0" {
		return "", fmt.Errorf("barcode not found")
	}
	return result.FoodID.Value, nil
}
