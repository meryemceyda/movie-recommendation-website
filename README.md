# NCFilm — Explainable Movie Recommendations

A PHP + SQLite movie discovery application built around an explainable content-based recommender, account-scoped movie lists, and a resilient TMDB poster integration. The catalog starts with 63 existing films. This project demonstrates application design, deterministic ranking, relational modeling, security controls and regression testing without an ML service or JavaScript framework.

**Status:** portfolio-oriented implementation, not a security certification or verified production deployment. Live TMDB coverage is not claimed to be 63/63. Previous reported local coverage was 54 matches; the user's latest SQLite cache is not available in the development environment.

## Features

- Registration, login and POST logout with hashed passwords and session-bound CSRF tokens.
- Private Watchlist, Watched and Favorites lists. Watched and Watchlist replace one another; Favorites is independent.
- Search by film title, multi-genre filtering, detail pages and responsive poster frames.
- Personalized genre-based recommendations with a short explanation for each result.
- Genre-diverse cold start when the profile has no usable genre information.
- TMDB server-side lookup, reviewed identity checks, cached posters, safe diagnostics, bounded retries and fallback artwork.
- An unmatched-only recovery mode that preserves every successful poster-cache row.
- PHP, HTTP, CLI, concurrency and recommendation regression tests in GitHub Actions.

Not implemented: email verification, password reset/change, MFA, account deletion, user ratings, collaborative filtering or a trained ML model.

## Technology

PHP 8.3, PDO/SQLite, HTML/CSS, small vanilla JavaScript image fallback handler. Python 3 standard library supports integration tests; Node.js runs the JS failure-handler test. No runtime package manager or paid recommendation service is required. HTTPS outbound access and PHP curl are required for TMDB lookup.

## Architecture

| Module | Responsibility |
| --- | --- |
| `movie-recommendation-website/index.php` | Public page/controller composition and escaped HTML |
| `app/http-actions.php`, `app/view-helpers.php` | POST action handling and shared list forms |
| `app/bootstrap.php`, `app/security.php` | Session/cookie policy, transport gate, escaping and safe exception events |
| `app/auth.php`, `app/auth-throttle.php` | Account authentication and atomic SQLite login reservations |
| `app/movies.php` | Parameterized film queries and account-scoped list mutations |
| `app/recommendations.php` | Pure deterministic ranking; no I/O, time or randomness |
| `app/recommendation-repository.php` | Loads the catalog and only the signed-in user's preference events |
| `app/recommendation-evaluation.php` | Positive-only offline leave-one-film-out metrics |
| `app/tmdb*.php`, `app/poster*.php` | API transport, identity validation, cache and recovery orchestration |
| `app/database.php`, `app/*schema.sql` | PDO connection, relational constraints and additive cache migrations |
| `bin/` | Setup, backup, tests, poster maintenance and offline evaluation |
| `var/` | Private SQLite data/backups; ignored by git and outside the document root |

HTTP mutations trust the session's user ID, never a submitted owner ID. Ranking receives already-loaded arrays and can be tested independently of PHP sessions, SQLite and TMDB. The implementation uses focused modules instead of introducing a framework migration solely for portfolio presentation.

## Database

| Table | Main relationship |
| --- | --- |
| `users` | Unique case-insensitive email and password hash |
| `movies` | Existing film ID, title, year, cast and synopsis |
| `genres`, `movie_genres` | Many-to-many movie/genre relation |
| `user_movies` | `(user_id, movie_id, list)` primary key; foreign keys to users/movies |
| `auth_attempts` | Expiring hashed IP and IP/account reservation buckets |
| `movie_posters` | One cached poster resolution per film, diagnostics and lease |
| `poster_service` | TMDB image configuration and shared cooldown |

Foreign keys and a SQLite busy timeout are enabled. Recommendations do not require new tables and do not mutate existing account, film, password, list or poster rows. Poster migration code adds missing cache columns without replacing business tables.

## Recommendation algorithm

For each preference film, Favorites contributes weight **3**, Watched contributes **1**; a film in both contributes **4**. Watchlist is not treated as a preference. Each film's weight is divided equally among its distinct genres, preventing multi-genre films from contributing more total signal.

For genre `g`, profile weight is:

```text
p[g] = sum(weight(movie) / number_of_genres(movie))
```

Candidate films use binary genre vectors. Their score is cosine similarity:

```text
score(movie) = sum(p[g] for g in movie.genres)
               / (sqrt(sum(p[g]^2)) * sqrt(number_of_genres(movie)))
```

- Watched and already-favorited films are excluded. Watchlist films can still be recommended.
- Scores are rounded to six decimals; ties use ascending film ID. Inputs/events are ordered and duplicate events do not inflate weights.
- Explanations name up to two genres with the strongest profile contributions.
- Positive-similarity films come first. Empty profiles or unused slots receive a deterministic discovery selection that greedily increases genre coverage, then uses the existing featured flag and film ID as tie-breakers.
- Discovery scores are **0**, not fabricated relevance probabilities. Scores are similarities, not predictions of enjoyment.
- Empty catalogs, missing genres, unknown history IDs and an all-watched catalog are handled without division by zero.

The repository uses a fixed number of bulk reads rather than one preference query per candidate. Ranking is approximately `O(events × genres + movies × genres + movies log movies)` for the content portion; discovery selection is `O(limit × movies × genres)`. These are complexity estimates, not measured latency claims. Genre-only signals cannot distinguish preferences within one genre; Watched is a weak positive assumption, not proof that a user liked the film.

## Local installation

Requirements: PHP 8.3 with PDO/pdo_sqlite/session; curl for TMDB. Test runner additionally needs Python 3 and Node.js. Ensure the PHP process can write the private `var/` directory.

For a **new empty installation only**:

```bat
git clone --branch feature/portfolio-recommendations https://github.com/meryemceyda/movie-recommendation-website.git
cd movie-recommendation-website
php bin/init-db.php
php -S 127.0.0.1:8000 -t movie-recommendation-website
```

Open http://127.0.0.1:8000. Register a real local account, mark movies as Favorites/Watched and return to Recommendations. The server is for local development only.

For an **existing installation** on Windows CMD:

```bat
git status
git fetch origin
git switch feature/portfolio-recommendations
php bin/backup-db.php
php bin/test.php
php -S 127.0.0.1:8000 -t movie-recommendation-website
```

Keep the existing `APP_DB_PATH` and TMDB environment settings. Branch switching does not reset ignored SQLite files. If git reports conflicting local changes, preserve them; do not force checkout. Do not delete the database, recreate it from seed, or replace it with a sample database. The backup command uses SQLite `VACUUM INTO` for a consistent new private snapshot and does not overwrite the original. Backups contain account data/password hashes and must not be published.

## Environment variables

| Variable | Purpose |
| --- | --- |
| `APP_DB_PATH` | Optional absolute SQLite file path; defaults to private `var/app.sqlite`. Keep the existing path during upgrades. |
| `APP_ENV` | `development` locally; `production` enables mandatory HTTPS for account pages. |
| `APP_HTTPS` | Set `1` only server-side behind a trusted TLS-terminating proxy. Arbitrary forwarded headers are ignored. |
| `TMDB_READ_ACCESS_TOKEN` | Preferred TMDB API Read Access Token, used as a server-side Bearer credential. |
| `TMDB_API_KEY` | Alternative v3 key; unnecessary when a read access token is set. |

`.env.example` is documentation, not an automatic dotenv loader. Set variables in the environment of the actual PHP process; never put credentials in source, client scripts, screenshots or git. On Windows use system/service environment settings and reopen CMD. Inspect configuration without printing secret values.

## TMDB operations and attribution

```bat
php bin/sync-posters.php --unresolved
php bin/sync-posters.php --retry-unmatched --max-wait=600
php bin/sync-posters.php --unresolved --json > var\unresolved-posters.json
```

`--unresolved` is an API-free report of that installation's unresolved cache entries. `--retry-unmatched` preserves all `matched` rows, including expired ones, and retries the rest. Normal sync retains its cache-refresh behavior. Identity/year/credits must be verified before a reviewed ID is accepted; uncertain candidates are never assigned arbitrary artwork.

See [TMDB operational guide](docs/tmdb.md), [reviewed IDs and source evidence](docs/reviewed-poster-identities.md), and [original catalog review](docs/catalog-review.md). Existing metadata contradictions are documented, not silently corrected.

The UI includes the official unmodified TMDB logo and the required notice: “This product uses the TMDB API but is not endorsed or certified by TMDB.” Artwork remains the property of its rights holders. Non-commercial developer API attribution does not grant a commercial license; review [TMDB terms and attribution](https://developer.themoviedb.org/docs/faq) before commercial use.

## Tests and offline evaluation

```bat
php bin/test.php
```

The runner stops on failure and executes PHP unit/domain tests, concurrent limiter reservations, actual poster CLI checks, real local HTTP sessions, production transport configuration and the JS fallback handler. Tests create separate in-memory/temporary databases; they do not reset the configured application database. Native PHP is authoritative for exit codes, cross-process SQLite locks and HTTP serving.

Individual suites are in `tests/`; GitHub Actions runs the same categories on PHP 8.3. See the [workflow](.github/workflows/php.yml). No live API token is needed in CI. TMDB failures are controlled fixtures, not claims that live posters were fetched. No production penetration, TLS certificate, browser layout, throughput or recommendation-quality benchmark is implied by a green build.

Optional local, aggregate-only evaluation:

```bat
php bin/evaluate-recommendations.php
```

Profiles with at least three distinct Favorites/Watched films are evaluated by holding out one film at a time, removing **all** events for that film, and recomputing top-6 recommendations. The command reports cases, Hit Rate@6 and MRR@6 without exporting identities. With insufficient data, metrics are null. Positive-only history and a small curated catalog create evaluation bias; these metrics are not online quality evidence. No real-dataset result is published because the user's current database was not available for evaluation.

## Security and deployment

See [security review and remaining risks](docs/security-review.md) and [engineering assessment](docs/engineering-assessment.md).

Production requires a real PHP web server with TLS. **Only `movie-recommendation-website/` may be the document root**; never serve the repo root. `app/`, `bin/`, `tests/`, `var/`, backups and environment files must remain private. The database-path guard is defense in depth, not a substitute for server configuration. Use maintained PHP, request/body limits, protected log rotation and restricted filesystem access. A reverse proxy must pass trustworthy HTTPS information through server configuration, not client-controlled headers.

Before deployment, validate certificate/proxy/cookie behavior, external file access, backup restoration, abuse controls and load on the actual host. Those checks have not been performed here. SQLite is suitable for this project's scale; concurrent writes are serialized and operational limits must be measured before scaling.

## Screenshots

No screenshots are committed yet. Capture the real local Recommendations, film detail and personal lists pages after verifying them; use a test account without exposing email addresses, credentials or private lists. Do not use mock screenshots as proof of working behavior. [Screenshot checklist](docs/screenshots/README.md).

## Known limitations and next steps

- Genre-only recommendations; no ratings, text embeddings or collaborative filtering.
- Six-result discovery may favor broad-genre films; metadata accuracy affects ranking.
- No email verification, password recovery/change, MFA, account deletion or all-device session revocation.
- Registration can reveal account availability; distributed login abuse still needs edge controls/monitoring.
- TMDB first lookup can occupy PHP workers; larger deployments should use a queue/cron and HTTP rate limits.
- SQLite LIKE is not fully Unicode-aware for Turkish search.
- Schema checks still run at connection time; an explicit versioned migration workflow and static analysis remain follow-up work.
- Live 63/63 artwork, real-user recommendation relevance, visual QA and production/load validation remain unverified.

## Verifiable portfolio claims

Implemented an explainable weighted content-based recommender; separated pure ranking from PDO persistence; modeled account-scoped many-to-many lists; implemented atomic SQLite authentication throttling, CSRF/session protections and safe API diagnostics; tested numeric scores, isolation, failure recovery and preservation of existing data in GitHub Actions. Do not claim a trained AI model, production scale, complete security audit or measured recommendation accuracy without additional evidence.
