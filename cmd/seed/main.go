package main

import (
	"context"
	"fmt"
	"os"

	"nutriflow/internal/config"
	"nutriflow/internal/db"
)

func main() {
	cfg, _ := config.Load()
	dbConn, err := db.Connect(context.Background(), cfg.DatabaseURL)
	if err != nil {
		fmt.Fprintf(os.Stderr, "Connect failed: %v\n", err)
		os.Exit(1)
	}
	defer dbConn.Close()

	repo := db.NewRepository(dbConn)
	
	f := &db.Food{
		ExternalSource: "manual",
		ExternalID:     "test-1",
		Name:           "Test Apple",
		Brand:          nil,
	}
	
	id, err := repo.UpsertFood(context.Background(), f)
	if err != nil {
		fmt.Printf("Upsert failed: %v\n", err)
		os.Exit(1)
	}
	
	fmt.Printf("Inserted food with ID: %s\n", id)
	
	// Insert serving
	servings := []db.Serving{
		{
			Description: "1 medium",
			MetricAmount: 182,
			MetricUnit: "g",
			Calories: 95,
			ProteinG: 0.5,
			CarbsG: 25,
			FatG: 0.3,
		},
	}
	
	if err := repo.ReplaceServings(context.Background(), id, servings); err != nil {
		fmt.Printf("ReplaceServings failed: %v\n", err)
	} else {
		fmt.Println("Servings inserted")
	}
}
