package main

import (
	"context"
	"fmt"
	"os"

	"github.com/jackc/pgx/v5"
)

func main() {
	// connect to default 'postgres' db
	url := "postgres://postgres:postgres@localhost:5432/postgres?sslmode=disable"
	conn, err := pgx.Connect(context.Background(), url)
	if err != nil {
		fmt.Fprintf(os.Stderr, "Unable to connect to database: %v\n", err)
		os.Exit(1)
	}
	defer conn.Close(context.Background())

	_, err = conn.Exec(context.Background(), "CREATE DATABASE nutriflow")
	if err != nil {
		fmt.Fprintf(os.Stderr, "Create database failed (might exist): %v\n", err)
	} else {
		fmt.Println("Database nutriflow created successfully")
	}
}
