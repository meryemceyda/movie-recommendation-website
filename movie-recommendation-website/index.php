<?php
header("Content-Type: text/html; charset=UTF-8");

$seciliKategori = isset($_GET["kategori"]) ? trim($_GET["kategori"]) : "";
$filmId = isset($_GET["id"]) ? trim($_GET["id"]) : "";

$detayAcik = ($filmId !== "");
$listeBaslik = ($seciliKategori === "") ? "Önerilenler" : ($seciliKategori . " Filmleri");

/* ===== filmler.php içeriği (fonksiyonlaştırıldı) ===== */
function renderFilmListesi() {
  // filmler.php mantığıyla aynı: önce poster göster kararını al, sonra GET'ten kategori oku
  global $seciliKategori;
  $posterGoster = ($seciliKategori === "");

  $seciliKategoriLocal = "";
  if (isset($_GET["kategori"]) && $_GET["kategori"] !== "") {
    $seciliKategoriLocal = trim($_GET["kategori"]);
  }

  $dosyaYolu = __DIR__ . "/filmler.txt";
  if (!file_exists($dosyaYolu)) {
    echo "filmler.txt bulunamadı.";
    exit;
  }

  $onerilenIDler = ["1", "11", "16", "17", "54", "47"];

  $dosya = fopen($dosyaYolu, "r");
  if (!$dosya) {
    echo "Dosya açılamadı.";
    exit;
  }

  while (($satir = fgets($dosya)) !== false) {
    $satir = trim($satir);
    if ($satir === "") continue;

    $parcalar = explode("|", $satir);
    if (count($parcalar) < 3) continue;

    $kategori = trim($parcalar[0]);
    $id       = trim($parcalar[1]);
    $ad       = trim($parcalar[2]);

    if ($seciliKategoriLocal !== "" && $kategori !== $seciliKategoriLocal) {
      continue;
    }

    if ($seciliKategoriLocal === "" && !in_array($id, $onerilenIDler, true)) {
      continue;
    }

    echo "<div class='film-karti' data-id='".htmlspecialchars($id, ENT_QUOTES)."'>";

    if ($posterGoster) {
      $posterYolu = "posters/" . $id . ".jpg";
      if (file_exists(__DIR__ . "/" . $posterYolu)) {
        echo "<img src='".htmlspecialchars($posterYolu, ENT_QUOTES)."' class='afis' alt='".htmlspecialchars($ad, ENT_QUOTES)."'>";
      }
    }

    echo "<h3>".htmlspecialchars($ad)."</h3>";
    echo "<p>Kategori: ".htmlspecialchars($kategori)."</p>";

    echo "<form method='get' action='index.php' style='margin:0;'>";
    if ($seciliKategoriLocal !== "") {
      echo "<input type='hidden' name='kategori' value='".htmlspecialchars($seciliKategoriLocal, ENT_QUOTES)."'>";
    }
    echo "<input type='hidden' name='id' value='".htmlspecialchars($id, ENT_QUOTES)."'>";
    echo "<button type='submit'>Detay</button>";
    echo "</form>";

    echo "</div>";
  }

  fclose($dosya);
}

/* ===== detay.php içeriği (fonksiyonlaştırıldı) ===== */
function renderFilmDetay($filmId) {
  if (!isset($_GET["id"]) || trim($_GET["id"]) === "") {
    echo "Film ID gönderilmedi.";
    exit;
  }

  $filmId = trim($filmId);

  $dosyaYolu = __DIR__ . "/detaylar.txt";
  if (!file_exists($dosyaYolu)) {
    echo "detaylar.txt bulunamadı.";
    exit;
  }

  $dosya = fopen($dosyaYolu, "r");
  if (!$dosya) {
    echo "Dosya açılamadı.";
    exit;
  }

  $bulundu = false;

  while (($satir = fgets($dosya)) !== false) {
    $satir = trim($satir);
    if ($satir === "") continue;

    $parcalar = explode("|", $satir);
    if (count($parcalar) < 6) continue;

    $id = trim($parcalar[0]);
    if ($id !== $filmId) continue;

    $ad       = trim($parcalar[1]);
    $yil      = trim($parcalar[2]);
    $kategori = trim($parcalar[3]);
    $basrol   = trim($parcalar[4]);
    $konu     = trim($parcalar[5]);

    echo "<div class='detay-wrap'>";
    echo "<h2 class='detay-baslik'>".htmlspecialchars($ad, ENT_QUOTES)."</h2>";
    echo "</div>";

    echo "<div class='detay'>";
    echo "<div>";
    echo "<p><strong>Yıl:</strong> ".htmlspecialchars($yil, ENT_QUOTES)."</p>";
    echo "<p><strong>Kategori:</strong> ".htmlspecialchars($kategori, ENT_QUOTES)."</p>";
    echo "<p><strong>Başrol:</strong> ".htmlspecialchars($basrol, ENT_QUOTES)."</p>";
    echo "<p><strong>Konusu:</strong> ".htmlspecialchars($konu, ENT_QUOTES)."</p>";

    echo "<form method='get' action='index.php' style='margin:0;'>";
    if (isset($_GET["kategori"]) && trim($_GET["kategori"]) !== "") {
      echo "<input type='hidden' name='kategori' value='".htmlspecialchars(trim($_GET["kategori"]), ENT_QUOTES)."'>";
    }
    echo "<button type='submit'>Listeye Dön</button>";
    echo "</form>";

    echo "</div>";
    echo "</div>";

    $bulundu = true;
    break;
  }

  fclose($dosya);

  if (!$bulundu) {
    echo "Film detayları bulunamadı.";
  }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>NCFilm</title>

  <!-- style.css gömüldü -->
  <style>
    :root{
      --bg: #f4b6c2;
      --bg2:#f7c9d3;
      --title:#b30059;
      --brand:#7a1fa2;
      --text:#ffffff;
      --btn:#c2185b;
      --btn2:#a3124a;
      --line: rgba(255,255,255,.35);
    }

    *{box-sizing:border-box}
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: #f4b6c2;
      color: #ffffff;
    }

    .topbar{
      position: sticky;
      top:0;
      z-index: 10;
      background: rgba(240,138,160,.55);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--line);
    }
    .topbar-inner{
      width: min(1100px, 92%);
      margin: 0 auto;
      padding: 14px 0;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
    }
    .brand{
      font-size: 30px;
      font-weight: 800;
      letter-spacing: 2px;
      background: linear-gradient(90deg, #7a1fa2, #c2185b);

      background-clip: text;
      -webkit-background-clip: text;
      color: transparent;
      -webkit-text-fill-color: transparent;
      transition: transform .2s ease;
      margin-left: -90px;
    }

    .kategori select {
      box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }

    .kategori{
      display:flex;
      align-items:center;
      gap: 10px;
    }
    .kategori-label{
      font-weight: 700;
      color: var(--text);
    }
    select{
      border: 1px solid var(--line);
      background: rgba(255,255,255,.20);
      color: #fff;
      padding: 10px 14px;
      border-radius: 999px;
      outline: none;
    }
    select option{ color:#222; }
    select:focus{
      box-shadow: 0 0 0 3px rgba(195,24,91,.25);
    }

    .container{
      width: min(1100px, 92%);
      margin: 18px auto 40px;
    }

    .card{
      background: linear-gradient(180deg, rgba(240,138,160,.60), rgba(240,138,160,.42));
      border: 1px solid var(--line);
      border-radius: 22px;
      padding: 16px;
      box-shadow: 0 20px 45px rgba(98, 0, 38, .18);
    }

    .section-title{
      margin: 2px 0 14px;
      color: var(--title);
      font-size: 20px;
      font-weight: 900;
      letter-spacing: .2px;
    }

    .grid{
      display:grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
    }
    @media (max-width: 900px){
      .grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 600px){
      .grid{ grid-template-columns: 1fr; }
    }

    .film-karti{
      background: linear-gradient(180deg, rgba(243,154,176,.65), rgba(243,154,176,.40));
      border: 1px solid var(--line);
      border-radius: 22px;
      padding: 12px;
      display:flex;
      flex-direction:column;
      gap: 8px;
      transition: transform .15s ease, filter .15s ease;
    }
    .film-karti:hover{
      transform: translateY(-2px);
      filter: brightness(1.03);
    }
    .film-karti h3{
      margin: 6px 0 0;
      font-size: 16px;
      font-weight: 800;
      color: #fff;
    }
    .film-karti p{
      margin: 0;
      font-size: 13px;
      opacity: .95;
    }

    .film-karti form { width: 100%; }
    .film-karti button { width: 100%; padding: 12px 0; }

    .afis{
      width: 100%;
      height: auto;
      max-height: 260px;
      object-fit: contain;
    }

    button{
      border: none;
      background: linear-gradient(180deg, var(--btn), var(--btn2));
      color: #fff;
      padding: 10px 14px;
      border-radius: 999px;
      font-weight: 800;
      letter-spacing: .2px;
      cursor: pointer;
      box-shadow: 0 10px 18px rgba(98,0,38,.20);
    }
    button:hover{ filter: brightness(1.06); }
    button:active{ transform: translateY(1px); }

    .detay-wrap{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 10px;
      margin-bottom: 12px;
    }
    .detay-baslik{
      margin:0;
      font-size: 22px;
      font-weight: 900;
      color: var(--title);
    }

    .detay p{
      max-width: 720px;
      margin: 8px auto;
      line-height: 1.4;
      text-align: left;
    }

    .detay button{
      display: block;
      margin: 18px auto 0;
    }

    .detay img{
      max-width: 240px;
      border-radius: 20px;
      border: 1px solid var(--line);
      display:block;
      margin: 0 auto 12px;
    }

    .detay strong{ color: var(--title); }

    @media (max-width: 700px){
      .detay{ grid-template-columns: 1fr; }
      .detay img{ width: 100% !important; }
    }

    .kurdele{
      position: fixed;
      font-size: 34px;
      z-index: 1;
      pointer-events: none;
      opacity: .9;
      animation: sallan 4.5s ease-in-out infinite;
      filter: drop-shadow(0 6px 10px rgba(0,0,0,.25));
    }

    .k-sol-1{ left: 95px; top: 160px; font-size: 75px; transform-origin: top left; }
    .k-sol-2{ left: 30px; top: 340px; font-size: 50px; animation-duration: 3.8s; }
    .k-sol-3{ left: 80px; top: 520px; font-size: 70px; animation-duration: 5.2s; }

    .k-sag-1{ right: 100px; top: 180px; font-size: 70px; transform-origin: top right; }
    .k-sag-2{ right: 30px; top: 360px; font-size: 55px; animation-duration: 4.1s; }
    .k-sag-3{ right: 80px; top: 540px; font-size: 75px; animation-duration: 5.6s; }

    @keyframes sallan{
      0%   { transform: rotate(0deg); }
      20%  { transform: rotate(6deg); }
      40%  { transform: rotate(-4deg); }
      60%  { transform: rotate(7deg); }
      80%  { transform: rotate(-5deg); }
      100% { transform: rotate(0deg); }
    }

    @media (max-width: 700px){
      .kurdele{ display:none; }
    }
  </style>
</head>

<body>

<div class="kurdele k-sol-1">🎀</div>
<div class="kurdele k-sol-2">🎀</div>
<div class="kurdele k-sol-3">🎀</div>

<div class="kurdele k-sag-1">🎀</div>
<div class="kurdele k-sag-2">🎀</div>
<div class="kurdele k-sag-3">🎀</div>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">NCFilm</div>

    <div class="kategori">
      <span class="kategori-label">Kategoriler</span>

      <form method="get" action="" style="margin:0;">
        <select id="kategori" name="kategori" onchange="this.form.submit()">
          <option value="" <?php echo ($seciliKategori==="" ? "selected" : ""); ?>>Önerilenler</option>
          <option value="Aksiyon" <?php echo ($seciliKategori==="Aksiyon" ? "selected" : ""); ?>>Aksiyon</option>
          <option value="Komedi" <?php echo ($seciliKategori==="Komedi" ? "selected" : ""); ?>>Komedi</option>
          <option value="Dram" <?php echo ($seciliKategori==="Dram" ? "selected" : ""); ?>>Dram</option>
          <option value="Bilim Kurgu" <?php echo ($seciliKategori==="Bilim Kurgu" ? "selected" : ""); ?>>Bilim Kurgu</option>
          <option value="Romantik" <?php echo ($seciliKategori==="Romantik" ? "selected" : ""); ?>>Romantik</option>
          <option value="Korku" <?php echo ($seciliKategori==="Korku" ? "selected" : ""); ?>>Korku</option>
          <option value="Yerli" <?php echo ($seciliKategori==="Yerli" ? "selected" : ""); ?>>Yerli</option>
        </select>

        <noscript><button type="submit">Göster</button></noscript>
      </form>
    </div>
  </div>
</header>

<main class="container">

  <section id="liste-alani" class="card" style="<?php echo $detayAcik ? "display:none;" : ""; ?>">
    <h2 id="listeBaslik" class="section-title"><?php echo htmlspecialchars($listeBaslik, ENT_QUOTES, "UTF-8"); ?></h2>

    <div id="filmListesi" class="grid">
      <?php renderFilmListesi(); ?>
    </div>
  </section>

  <section id="detay-alani" class="card" style="<?php echo $detayAcik ? "" : "display:none;"; ?>">
    <h2 class="section-title">Film Detayları</h2>
    <div id="filmDetay">
      <?php
      if ($detayAcik) {
        renderFilmDetay($filmId);
      }
      ?>
    </div>
  </section>

</main>

</body>
</html>
