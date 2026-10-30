-- Additive migration only: never rebuild movies/users/user_movies.
CREATE TABLE IF NOT EXISTS movie_posters (
    movie_id INTEGER PRIMARY KEY REFERENCES movies(id) ON DELETE CASCADE,
    source_key TEXT NOT NULL DEFAULT '',
    tmdb_id INTEGER,
    poster_path TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    next_check INTEGER NOT NULL DEFAULT 0,
    lease_until INTEGER NOT NULL DEFAULT 0,
    checked_at INTEGER,
    image_base TEXT,
    card_size TEXT,
    detail_size TEXT,
    last_error TEXT,
    match_reason TEXT
);
CREATE TABLE IF NOT EXISTS poster_service (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
