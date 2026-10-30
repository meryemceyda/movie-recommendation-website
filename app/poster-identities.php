<?php
declare(strict_types=1);
// IDs verified against the cited public TMDB pages, 2026-10-30.
// Runtime API checks are still mandatory. No poster URLs or secrets are stored here.
return [
    ['title' => 'La La Land', 'year' => 2016, 'tmdb_id' => 313369,
        'titles' => ['La La Land', 'Aşıklar Şehri'], 'director' => 'Damien Chazelle', 'cast' => ['Ryan Gosling', 'Emma Stone'],
        'source' => 'https://www.themoviedb.org/movie/313369-la-la-land'],
    ['title' => 'It', 'year' => 2017, 'tmdb_id' => 346364,
        'titles' => ['It', 'O'], 'director' => 'Andy Muschietti', 'cast' => ['Bill Skarsgård', 'Finn Wolfhard'],
        'source' => 'https://www.themoviedb.org/movie/346364-it'],
    ['title' => 'Terrifer', 'source_titles' => ['Terrifier'], 'year' => 2016, 'tmdb_id' => 420634,
        'titles' => ['Terrifier'], 'director' => 'Damien Leone', 'cast' => ['David Howard Thornton', 'Jenna Kanell'],
        'premiere_country' => 'US',
        'source' => 'https://www.themoviedb.org/movie/420634-terrifier',
        'release_source' => 'https://www.themoviedb.org/movie/420634-terrifier/releases'],
];
