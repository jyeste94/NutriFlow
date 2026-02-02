CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

CREATE TABLE foods (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    external_source TEXT NOT NULL, -- 'fatsecret', 'manual', etc.
    external_id TEXT NOT NULL,
    name TEXT NOT NULL,
    brand TEXT,
    barcode TEXT,
    best_serving_id UUID, -- Circular dependency, but handled logically
    last_fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT unique_external UNIQUE (external_source, external_id)
);

CREATE INDEX idx_foods_barcode ON foods(barcode);
CREATE INDEX idx_foods_name_trgm ON foods USING gin (name gin_trgm_ops);

CREATE TABLE servings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    food_id UUID NOT NULL REFERENCES foods(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    metric_amount NUMERIC NOT NULL,
    metric_unit TEXT NOT NULL,
    calories NUMERIC NOT NULL,
    protein_g NUMERIC NOT NULL,
    carbs_g NUMERIC NOT NULL,
    fat_g NUMERIC NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Index for FK
CREATE INDEX idx_servings_food_id ON servings(food_id);
