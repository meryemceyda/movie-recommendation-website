<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$db = database();
$user = current_user($db);
$page = input($_GET, 'page') ?: 'catalog';
$error = '';
$email = '';
$name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], input($_POST, 'csrf'))) {
        http_response_code(403);
        exit('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
    }
    $action = input($_POST, 'action');
    if ($action === 'logout') logout();
    if ($action === 'register' || $action === 'login') {
        $page = $action;
        $email = input($_POST, 'email');
        $name = input($_POST, 'name');
        $error = authenticate($db, $action, $name, $email, input($_POST, 'password')) ?? '';
        if ($error === '') {
            flash($action === 'register' ? 'Hesabınız oluşturuldu.' : 'Giriş yapıldı.');
            redirect('page=lists');
        }
    } elseif ($action === 'save') {
        if (!$user) {
            flash('Film listelerinizi düzenlemek için giriş yapın.');
            redirect('page=login');
        }
        $id = filter_var(input($_POST, 'movie_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !save_movie($db, (int) $user['id'], $id, input($_POST, 'list'), input($_POST, 'operation'))) {
            http_response_code(400);
            exit('Geçersiz film veya liste işlemi.');
        }
        flash('Film listeniz güncellendi.');
        $returnList = input($_POST, 'return_list');
        if (isset(LISTS[$returnList])) redirect('page=lists&list=' . urlencode($returnList));
        redirect('id=' . $id);
    } else {
        http_response_code(400);
        exit('Geçersiz işlem.');
    }
}

if (!in_array($page, ['catalog', 'lists', 'login', 'register'], true)) {
    http_response_code(404);
    exit('Sayfa bulunamadı.');
}
if ($page === 'lists' && !$user) {
    flash('Listelerinizi görmek için giriş yapın.');
    redirect('page=login');
}
if (($page === 'login' || $page === 'register') && $user) redirect('page=lists');

$list = $page === 'lists' ? (input($_GET, 'list') ?: 'watchlist') : '';
if ($list !== '' && !isset(LISTS[$list])) {
    http_response_code(400);
    exit('Geçersiz liste.');
}
$genre = trim(input($_GET, 'kategori'));
$search = substr(trim(input($_GET, 'q')), 0, 200);
$all = input($_GET, 'all') === '1';
$genres = $db->query('SELECT name FROM genres ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$movie = null;
$notFound = false;
if (array_key_exists('id', $_GET)) {
    $id = filter_var(input($_GET, 'id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $movie = $id ? find_movie($db, $id) : null;
    if (!$movie) {
        http_response_code(404);
        $notFound = true;
    }
}
$featured = $page === 'catalog' && !$all && $genre === '' && $search === '';
$movies = ($page === 'catalog' || $page === 'lists') && !$movie && !$notFound
    ? list_movies($db, $user ? (int) $user['id'] : null, $list, $genre, $search, $featured) : [];
$memberships = [];
if ($user) {
    $statement = $db->prepare('SELECT movie_id, list FROM user_movies WHERE user_id = ?');
    $statement->execute([$user['id']]);
    foreach ($statement as $entry) $memberships[(int) $entry['movie_id']][$entry['list']] = true;
}
$notice = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$title = $movie ? $movie['title'] : match ($page) {
    'login' => 'Giriş yap',
    'register' => 'Hesap oluştur',
    'lists' => LISTS[$list],
    default => $featured ? 'Önerilenler' : 'Filmler',
};

function list_actions(int $movieId, array $memberships, string $returnList = ''): void
{
    echo '<div class="actions">';
    foreach (LISTS as $key => $label) {
        $saved = isset($memberships[$movieId][$key]);
        echo '<form method="post" action="index.php">';
        csrf_field();
        echo '<input type="hidden" name="action" value="save">';
        echo '<input type="hidden" name="movie_id" value="' . $movieId . '">';
        echo '<input type="hidden" name="list" value="' . e($key) . '">';
        echo '<input type="hidden" name="return_list" value="' . e($returnList) . '">';
        echo '<input type="hidden" name="operation" value="' . ($saved ? 'remove' : 'add') . '">';
        $text = $saved ? $label . ' listesinden çıkar' : $label . ' listesine ekle';
        echo '<button type="submit">' . e($text) . '</button></form>';
    }
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> · NCFilm</title>
  <link rel="stylesheet" href="style.css">
  <script src="posters.js" defer></script>
</head>
<body>
<header class="topbar"><div class="topbar-inner">
  <a class="brand" href="index.php">NCFilm</a>
  <nav aria-label="Ana menü">
    <a href="index.php">Önerilenler</a>
    <a href="index.php?all=1">Tüm filmler</a>
    <?php if ($user): ?>
      <a href="index.php?page=lists">Listelerim</a>
      <span><?= e($user['name']) ?></span>
      <form method="post" action="index.php"><?php csrf_field(); ?><input type="hidden" name="action" value="logout"><button>Çıkış</button></form>
    <?php else: ?>
      <a href="index.php?page=login">Giriş yap</a><a href="index.php?page=register">Kayıt ol</a>
    <?php endif; ?>
  </nav>
</div></header>
<main class="container">
  <?php if ($notice): ?><div class="notice" role="status"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <?php if ($page === 'login' || $page === 'register'): ?>
    <section class="card auth">
      <h1 class="section-title"><?= $page === 'register' ? 'Hesap oluştur' : 'Giriş yap' ?></h1>
      <p>Film listeleriniz yalnızca kendi hesabınızda görünür.</p>
      <form method="post" action="index.php?page=<?= e($page) ?>">
        <?php csrf_field(); ?><input type="hidden" name="action" value="<?= e($page) ?>">
        <?php if ($page === 'register'): ?>
          <label for="name">Adınız</label><input id="name" name="name" value="<?= e($name) ?>" maxlength="100" autocomplete="name" required>
        <?php endif; ?>
        <label for="email">E-posta</label><input id="email" type="email" name="email" value="<?= e($email) ?>" maxlength="254" autocomplete="email" required>
        <label for="password">Parola</label><input id="password" type="password" name="password" autocomplete="<?= $page === 'register' ? 'new-password' : 'current-password' ?>" <?= $page === 'register' ? 'minlength="10"' : '' ?> maxlength="72" required>
        <?php if ($page === 'register'): ?><p class="muted">10–72 bayt kullanın. Türkçe karakterler birden fazla bayt yer kaplayabilir.</p><?php endif; ?>
        <button type="submit"><?= $page === 'register' ? 'Kayıt ol' : 'Giriş yap' ?></button>
      </form>
      <p><a href="index.php?page=<?= $page === 'register' ? 'login' : 'register' ?>"><?= $page === 'register' ? 'Zaten hesabım var' : 'Hesap oluştur' ?></a></p>
    </section>
  <?php elseif ($notFound): ?>
    <section class="card"><h1>Film bulunamadı</h1><a href="index.php?all=1">Filmlere dön</a></section>
  <?php elseif ($movie): ?>
    <section class="card">
      <h1 class="detay-baslik"><?= e($movie['title']) ?></h1>
      <?php render_poster($movie, true); ?>
      <p><strong>Yıl:</strong> <?= (int) $movie['year'] ?></p>
      <p><strong>Kategoriler:</strong> <?= e(movie_genres($db, (int) $movie['id'])) ?></p>
      <p><strong>Başrol:</strong> <?= e($movie['cast_names']) ?></p>
      <p><strong>Konusu:</strong> <?= e($movie['synopsis']) ?></p>
      <?php if ($user): ?>
        <?php list_actions((int) $movie['id'], $memberships, $list); ?>
        <p class="muted">İzlendi ve İzlenecekler birbirinin yerine geçer. Favoriler bağımsızdır.</p>
      <?php else: ?>
        <p><a href="index.php?page=login">Listene eklemek için giriş yap</a></p>
      <?php endif; ?>
      <p><a href="index.php?<?= e(http_build_query(['page' => $page, 'list' => $list, 'kategori' => $genre, 'q' => $search, 'all' => $all ? '1' : '0'])) ?>">Listeye dön</a></p>
    </section>
  <?php else: ?>
    <section class="card">
      <h1 class="section-title"><?= e($title) ?></h1>
      <?php if ($page === 'lists'): ?>
        <nav class="tabs" aria-label="Film listeleri">
        <?php foreach (LISTS as $key => $label): ?><a <?= $list === $key ? 'aria-current="page" class="active"' : '' ?> href="index.php?page=lists&amp;list=<?= e($key) ?>"><?= e($label) ?></a><?php endforeach; ?>
        </nav>
      <?php elseif ($featured): ?><p class="muted">Önceden seçilmiş 6 film. Tüm kataloğu görmek için “Tüm filmler” bağlantısını kullanın.</p><?php endif; ?>
      <form class="filters" method="get" action="index.php">
        <input type="hidden" name="page" value="<?= e($page) ?>">
        <input type="hidden" name="all" value="1">
        <?php if ($list): ?><input type="hidden" name="list" value="<?= e($list) ?>"><?php endif; ?>
        <div><label for="kategori">Kategori</label><select id="kategori" name="kategori"><option value="">Tüm kategoriler</option>
          <?php foreach ($genres as $option): ?><option value="<?= e($option) ?>" <?= $genre === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?>
        </select></div>
        <div><label for="q">Film adı</label><input id="q" name="q" maxlength="200" value="<?= e($search) ?>" placeholder="Film ara"></div>
        <button type="submit">Filtrele</button>
        <a href="index.php?<?= e(http_build_query(['page' => $page, 'list' => $list, 'all' => '1'])) ?>">Temizle</a>
      </form>
      <p><?= count($movies) ?> film</p>
      <?php if (!$movies): ?><p>Bu görünümde film yok. <?php if ($page === 'lists'): ?><a href="index.php?all=1">Katalogdan film ekleyin.</a><?php endif; ?></p><?php endif; ?>
      <div class="grid">
      <?php foreach ($movies as $item): ?>
        <article class="film-karti">
          <?php render_poster($item); ?>
          <h2><?= e($item['title']) ?></h2>
          <p><?= (int) $item['year'] ?> · <?= e(movie_genres($db, (int) $item['id'])) ?></p>
          <?php if (isset($memberships[(int) $item['id']])): ?><p><?= e(implode(' · ', array_map(fn($key) => LISTS[$key], array_keys($memberships[(int) $item['id']])))) ?></p><?php endif; ?>
          <a href="index.php?<?= e(http_build_query(['id' => $item['id'], 'page' => $page, 'list' => $list, 'kategori' => $genre, 'q' => $search, 'all' => $all ? '1' : '0'])) ?>">Detay ve liste işlemleri</a>
          <?php if ($page === 'lists'): list_actions((int) $item['id'], $memberships, $list); endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>
<footer class="container credits" aria-labelledby="credits-title">
  <h2 id="credits-title">Kaynaklar ve atıf</h2>
  <a href="https://www.themoviedb.org" rel="noreferrer"><img class="tmdb-logo" src="assets/tmdb-logo.svg" width="100" height="13" alt="TMDB — The Movie Database"></a>
  <p>Film afişlerinin kaynağı TMDB'dir. Görsellerin hakları ilgili hak sahiplerine aittir.</p>
  <p lang="en">This product uses the TMDB API but is not endorsed or certified by TMDB.</p>
</footer>
</body>
</html>
