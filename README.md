# NCFilm — Movie Catalog & Personal Lists

PHP ve SQLite ile geliştirilen film kataloğu. 63 film, çoklu kategori filtreleme, film adıyla arama, kullanıcı hesapları ve kullanıcıya özel film listeleri içerir.

## Özellikler

- Film detayları: yıl, türler, başrol ve konu; tüm filmler için otomatik TMDB afiş eşleştirmesi ve önbellek.
- Kayıt, giriş ve POST ile çıkış; hesaplar benzersiz e-posta adresi kullanır.
- **İzlenecekler**, **İzlendi**, **Favoriler** listeleri; listelere ekleme ve çıkarma.
- İzlendi/İzlenecekler durumları birbirinin yerine geçer; Favoriler bağımsızdır.
- Liste erişimi oturumdaki kullanıcı kimliğiyle sınırlandırılır.
- PDO prepared statements, parola hashleme, CSRF tokenleri, girişte oturum kimliği yenileme, HttpOnly/SameSite çerezleri ve veritabanında tutulan giriş denemesi sınırı.
- 2 saat hareketsizlikten sonra oturum sona erer. Kimlik doğrulamada e-posta veya IP başına 15 dakikada en fazla 10 deneme yapılabilir; başarılı denemeler de kotaya dahildir.

**Önerilenler kişiselleştirilmiş değildir:** mevcut altı filmlik sabit seçkidir. Bu sürüm makine öğrenmesi veya kullanıcı davranışına dayalı öneri algoritması içermez.

## Gereksinimler

- PHP **8.1+** ve `PDO`, `pdo_sqlite`, `session` ve TMDB bağlantısı için `curl` eklentileri. CI PHP 8.3 ile çalışır.
- Uygulama sürecinin yazabildiği `var/` dizini; SQLite için ayrı sunucu veya parola gerekmez.
- HTTP testleri için Python 3 (ek paket gerekmez).

## Yerel kurulum

```sh
git clone https://github.com/meryemceyda/movie-recommendation-website.git
cd movie-recommendation-website
php bin/init-db.php
php -S 127.0.0.1:8000 -t movie-recommendation-website
```

Tarayıcıda **http://127.0.0.1:8000** adresini açın. Önce **Kayıt ol** ile kendi hesabınızı oluşturun. Film detayındaki düğmelerden listeye ekleyin, **Listelerim** bölümünden görüntüleyin veya çıkarın. Test hesabı ya da varsayılan parola yoktur.

Başlatma komutu `var/app.sqlite` oluşturur ve metin dosyalarındaki 63 filmi tek transaction içinde içeri aktarır. Yeniden çalıştırmak mevcut filmleri, kullanıcıları veya listeleri silmez. Metin dosyaları yalnızca ilk aktarımın kaynağıdır; sonradan uygulama verileri SQLite'tan okur. Detaylardaki ek türler de filtrelemeye katılır; eski katalog kategorileri korunur. Kaynaktaki film bilgilerinin doğruluğu ayrıca gözden geçirilmelidir.

### Yapılandırma ve yayınlama

- İsteğe bağlı `APP_DB_PATH`, veritabanı dosyasının **mutlak** yolunu belirler. Hem kurulumda hem sunucuda aynı değer kullanılmalıdır. `.env` dosyası otomatik okunmaz; ortam değişkenini sunucuda tanımlayın.
- **Web sunucusunun document root'u yalnızca `movie-recommendation-website/` olmalıdır.** Repo kökünü sunmayın. `app/`, `bin/`, `tests/` ve `var/` herkese açık olmamalıdır; kullanıcı/parola hashleri `var/` içinde tutulur.
- Yayında HTTPS kullanın. TLS reverse proxy'de sonlanıyorsa `APP_HTTPS=1` tanımlayın; bu seçenek oturum çerezine Secure bayrağını ekler. HTTP yerel geliştirmede bu ayarı açmayın.
- PHP'nin geliştirme sunucusu yalnızca yerel deneme içindir. Yayında PHP-FPM/Apache gibi bir PHP sunucusu, kalıcı ve yazılabilir veri diski, kapalı `display_errors` ve erişimi kısıtlı hata kayıtları kullanın.
- SQLite dosyasını ve yedeklerini git'e eklemeyin. Veri yedeği için uygulama yazımlarını durdurup veritabanını kopyalayın veya SQLite backup API kullanın.

## Mimari

| Yol | Sorumluluk |
| --- | --- |
| `movie-recommendation-website/index.php` | İstek yönlendirme, formlar ve HTML görünümü |
| `movie-recommendation-website/style.css` | Mevcut pembe temadan ayrıştırılmış stil |
| `app/bootstrap.php` | Oturum, CSRF, çıktı yardımcıları ve güvenlik başlıkları |
| `app/auth.php` | Kayıt, giriş, çıkış ve deneme sınırı |
| `app/movies.php` | Film sorguları ve kullanıcı listesi işlemleri |
| `app/database.php`, `app/schema.sql` | PDO bağlantısı, şema ve ilk veri aktarımı |
| `bin/init-db.php` | Veritabanını hazırlayan CLI komutu |
| `var/` | Git dışında tutulan kalıcı SQLite verisi |

`users` ve `movies`, `user_movies` üzerinden bağlanır. `(user_id, movie_id, list)` birincil anahtarı aynı filme aynı listede yinelenen kayıt eklenmesini engeller. `movies` ve `genres` çoktan çoğa `movie_genres` ilişkisi kullanır. Foreign key kısıtları etkindir.

## Testler

```sh
find app bin tests movie-recommendation-website -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/domain.php
python3 tests/http_test.py
```

Testler ayrı bellek/geçici veritabanları kullanır; gerçek hesapları değiştirmez. Veri aktarımı ve tekrar çalıştırılması, parola hashleri, giriş denemesi sınırı, liste durum geçişleri, iki hesap arasında izolasyon, SQL/HTML girdileri, CSRF, çıkış/giriş sonrası kalıcılık ve özel dosyalara HTTP erişiminin engellenmesi kontrol edilir. GitHub Actions aynı kontrolleri push ve pull request olaylarında çalıştırır.

## Kapsam dışında

Parola sıfırlama, e-posta doğrulama, hesap silme, yönetici paneli, dış film API'si ve kişiselleştirilmiş öneriler henüz yoktur. Film adı araması SQLite `LIKE` kullanır; Türkçe büyük/küçük harf eşleştirmesi tam Unicode desteğine sahip değildir. İleride bu özellikler ayrı değişiklikler olarak eklenebilir.


## Otomatik TMDB afişleri

### Bir defalık yapılandırma

1. [TMDB hesabınızın API ayarlarından](https://www.themoviedb.org/settings/api) geliştirici erişimi alın ve TMDB koşullarını kabul edin.
2. **API Read Access Token** değerini PHP sunucusunun `TMDB_READ_ACCESS_TOKEN` ortam değişkenine tanımlayın. Alternatif olarak v3 anahtarı için `TMDB_API_KEY` kullanılabilir; ikisi de varsa Bearer token tercih edilir. Anahtarı GitHub'a, HTML/JavaScript'e veya URL'lere elle eklemeyin.
3. PHP `curl` eklentisini etkinleştirin. Sunucunun `api.themoviedb.org:443` adresine ve ziyaretçinin `image.tmdb.org:443` adresine erişebilmesi gerekir. TLS doğrulamasını kapatmayın.
4. PHP sürecini yeniden başlatın. Mevcut veritabanı kullanılıyorsa **aynı `APP_DB_PATH` değerini koruyun**. SQLite dosyasını silmeyin, değiştirmeyin veya yeni boş dosyayla değiştirmeyin.

Yerel Bash örneği (değeri gizli okuyarak; repo kökünden):

```sh
read -rsp 'TMDB API Read Access Token: ' TMDB_READ_ACCESS_TOKEN
export TMDB_READ_ACCESS_TOKEN
printf '\n'
php -S 127.0.0.1:8000 -t movie-recommendation-website
```

PowerShell için mevcut terminal oturumunda `$env:TMDB_READ_ACCESS_TOKEN` değişkenini ayarlayın. Apache/PHP-FPM ve hosting ortamlarında bunu servis/hosting ortam değişkeni ayarlarından tanımlayın; PHP-FPM ortamı temizliyorsa bu değişkeni ilgili pool'a açıkça aktarın. `.env.example` yalnızca örnektir; `.env` otomatik okunmaz ve web köküne konulmamalıdır. Tokeni terminal geçmişine veya ekran görüntüsüne kaydetmeyin.

Yeni kurulumlarda önce normal `php bin/init-db.php` komutunu çalıştırın. Var olan kurulumlarda afiş tabloları ilk veritabanı bağlantısında otomatik ve tekrar çalıştırılabilir biçimde eklenir. Bu güncelleme `movies`, `users`, `user_movies`, kategori tablolarını veya mevcut satırları değiştirmez; hesap ve listeleri sıfırlamaz.

### Otomatik çalışma

- Her kart/detay afişi yerel `poster.php?id=…&size=card|detail` adresini kullanır. **Anahtar yalnızca sunucuda kalır.** Bu adres API isteğini sunucuda yapar, başarılı olduğunda TMDB CDN görseline yönlendirir.
- Filmin Türkçe adı ve varsa orijinal ad arama takma adlarıyla TMDB araması yapılır. **Ad ve çıkış yılı birlikte doğrulanır**, detay yanıtında da yeniden kontrol edilir. Noktalama/Türkçe harf normalizasyonu yapılır; yıl farkı, birden fazla güçlü aday veya kesilmiş arama sonuçlarında rastgele afiş seçilmez. Takma adlar TMDB kimliği/afiş URL'si içermez ve yıl kontrolünü atlamaz.
- Görsellerde filmin orijinal dili tercih edilir; yoksa Türkçe, İngilizce veya dil bağımsız afiş kullanılabilir. CDN tabanı ve desteklenen boyutlar `/configuration` üzerinden alınır. Kartlarda `w500`, detayda `w780` kullanılır (desteklenmiyorsa doğrulanmış alternatif).
- Pozitif eşleşmeler 7 gün, eşleşmeyenler 1 gün, geçici hatalar genellikle 60 saniye önbelleğe alınır. Süre dolunca **bir sonraki afiş isteği** otomatik yeniler. İlk ziyarette yalnızca görünür kartlar `loading="lazy"` ile yüklenir; açılmayan filmler ilk görüntülendiklerinde çözülür. Sayfa HTML'i TMDB araması beklemez.
- Aynı film için eşzamanlı aramaları 900 saniyelik lease sınırlar. TMDB `429` yanıtında `Retry-After` dikkate alınarak tüm aramalara ara verilir; geçersiz anahtar için 1 saat beklenir. İsteklerde bağlantı/toplam süre ve yanıt boyutu sınırları vardır.
- Anahtar yoksa, TMDB kapalıysa, afiş bulunamazsa veya CDN görseli tarayıcıda yüklenemezse yerel varsayılan SVG gösterilir. Geçici hatalarda daha önce doğrulanmış afiş korunur. Ad/yıl değiştiğinde eski eşleşme geçersizleşir. Başarılı yönlendirmeler tarayıcıda 1 saat, varsayılanlar 30 saniye önbelleğe alınır; yapılandırmadan sonra gerekirse sayfayı zorla yenileyin.
- Görseller 2:3 çerçevede **kırpılmadan ve esnetilmeden** gösterilir. Detay çerçevesi 420 CSS piksele kadar büyür; mobilde kullanılabilir genişliği aşmaz.

İsteğe bağlı, kataloğun tamamını ilk ziyaretten önce hazırlama/raporlama:

```sh
php bin/sync-posters.php
```

Bu komut zorunlu değildir ve her yüklemede çalıştırılmaz. Aynı ortam değişkenlerini kullanır; önbelleğe saygı gösterir, 63 filmin durumunu raporlar. `matched`, `not_found`, `ambiguous`, `no_poster`, `error`, `pending` durumları görülebilir. Hata/eksik yapılandırmada sıfır dışı çıkış kodu üretir. Dilerseniz sunucunuzun cron görevine ekleyebilirsiniz; normal sitede afişler ziyaretle otomatik tamamlanır ve yenilenir.

### Katalog doğruluğu ve kapsam

Mevcut 63 satır incelendi; [katalog incelemesi](docs/catalog-review.md) ad/yıl listesini içerir. **Canlı TMDB erişim anahtarı olmadan 63/63 gerçek afiş eşleşmesi doğrulanmış değildir.** Offline testler, gerçek afişlerin bulunmuş olduğu anlamına gelmez.

Özellikle `Evdeki Düşman` (2016, James McAvoy) satırında ad/yıl/oyuncu çelişkisi, `Başka Bir Sen` (2015) satırında olası yıl hatası vardır. Veriler otomatik değiştirilmez veya farklı filmle sessizce eşleştirilmez. Bu kayıtlar güvenli eşleşme bulunana kadar varsayılan görselde kalabilir. `Terrifer` için `Terrifier` arama takma adı eklenmiştir; yine 2016 yılı doğrulanır. Doğru metadata düzeltildikten sonra sistem yeni ad/yıl anahtarıyla otomatik tekrar arar. Yanlış afişle 63/63 görünüm elde etmek yerine doğrulanamayan kayıt açık bırakılır.

### TMDB kullanımı ve atıf

Sayfanın **Kaynaklar ve atıf** bölümünde TMDB'nin resmi logosu, `https://www.themoviedb.org` bağlantısı ve şu bildirim bulunur:

> This product uses the TMDB API but is not endorsed or certified by TMDB.

Logo resmi onaylı SVG kaynağından değiştirilmeden alınmıştır; rengi/en-boy oranı korunur ve NCFilm markasından daha küçük gösterilir. Kaynak: https://www.themoviedb.org/assets/v4/logos/v2/blue_short-8e7b30f73a4020692ccca9c88bafe5dcb6f8a62a4c6bc55cd9ba82bb2cd95f6c.svg

Afişlerin mülkiyeti uygulamaya veya TMDB'ye aitmiş gibi sunulmaz. Afiş dosyaları repoya topluca kopyalanmaz; doğrulanmış `poster_path` üzerinden TMDB CDN'den gösterilir. Geliştirici API'si atıfla **ticari olmayan kullanım** içindir; gelir amaçlı kullanımda TMDB ile ticari lisans koşullarını görüşün. Hesap sahibinin kabul ettiği güncel API koşulları geçerlidir.

Resmi kaynaklar: [API başlangıcı](https://developer.themoviedb.org/docs/getting-started), [uygulama kimlik doğrulaması](https://developer.themoviedb.org/docs/authentication-application), [film arama](https://developer.themoviedb.org/reference/search-movie), [görsel URL'leri](https://developer.themoviedb.org/docs/image-basics), [hız sınırları](https://developer.themoviedb.org/docs/rate-limiting), [atıf ve kullanım](https://developer.themoviedb.org/docs/faq), [onaylı logolar](https://www.themoviedb.org/about/logos-attribution).

### Afiş testleri

```sh
php tests/posters.php
node tests/posters-js.cjs
python3 tests/http_test.py
```

Offline fixture testleri: 63 satırlık afiş akışı, ad/yıl doğrulaması, yanlış yıl/başlık, belirsiz sonuçlar, detay doğrulaması, orijinal dil tercihi, bozuk yol/host engelleme, cache hit, süre sonu, metadata değişikliği, API hatası/429 bekleme, eşzamanlı arama kilidi, anahtarsız çalışma, veri kaybetmeyen geçiş ve mevcut satırların korunması. HTTP testleri 63 afiş uç noktasını ve hesap/liste işlevlerini tekrar kontrol eder. JS testi CDN yükleme hatasının varsayılan görsele döndüğünü kontrol eder. Canlı arama sonucu ve görsel tasarım kontrolü ayrı doğrulamalardır.


## TMDB hata teşhisi ve güvenli yeniden deneme

Önceki sürüm tüm istisnaları tek bir genel `error` durumuna indiriyor, HTTP/cURL ayrıntılarını saklamıyor ve başarısız sonuçları bir saat önbellekte tutuyordu. Bu nedenle eski bir `error` kaydından **orijinal HTTP kodunu geriye dönük çıkarmak mümkün değildir**. Güncellenmiş sürüm bu kayıtları `legacy_error` olarak açıklar; neden uydurmaz. `16 matched / 47 error` sayımı tek başına bağlantı sorunu, kota veya eşleşme sorununun kanıtı değildir.

### Kurtarma (mevcut veritabanını koruyarak)

Aynı `APP_DB_PATH`, PHP curl ve TMDB ortam değişkenleriyle:

```sh
php bin/sync-posters.php --retry-errors
```

Bu seçenek yalnızca hata önbelleğini yeniden denemeye açar. Geçerli `matched` kayıtlarını yeniden aramaz; filmleri, kullanıcıları, parola hashlerini ve kişisel listeleri silmez. Veritabanını silmeyin ve tekrar seed ederek sorunu çözmeye çalışmayın. Şema geçişi yalnızca afiş önbelleğine `last_error` ve `match_reason` sütunlarını ekler; önceki afiş verileri de korunur.

Tek filmi incelemek veya makinece okunabilir rapor almak için:

```sh
php bin/sync-posters.php --retry-errors --movie=34
php bin/sync-posters.php --retry-errors --json > var/tmdb-report.json
```

JSON modunda ilerleme/bekleme mesajları stderr'e, sonuç raporu stdout'a gider. `var/` git dışında kalır ve web kökünün dışındadır. Hata varsa çıkış kodu `1`, yanlış seçenek/olmayan filmde `2` döner; bu, JSON raporunun geçersiz olduğu anlamına gelmez. CLI yalnızca poster arama/önbellek işlemini çalıştırır; eksik kataloğu kendiliğinden seed etmez.

Her satırda durum, cache/lookup bilgisi, HTTP kodu, TMDB uygulama hata kodu, cURL numarası, deneme sayısı, güvenli endpoint adı, arındırılmış hata mesajı ve sonraki deneme zamanı raporlanır. `http=0`, HTTP yanıtı alınamadığını gösterir; HTTP durum kodu değildir. `attempts`, **hata veren tek API çağrısının** deneme sayısıdır; filmin tüm arama isteklerinin toplamı değildir. Rapor saatleri açık UTC ofsetiyle ISO 8601 biçimindedir. Geçerli eski afiş bir yenileme hatası sırasında korunursa `matched` ile birlikte hata uyarısı gösterilir ve işlem sıfır dışı çıkar.

Aşağıdaki örnek sentetiktir; kullanıcının geçmiş çalıştırmasından alınmamıştır:

```text
17 | Dövüş Kulübü (1999) | error | lookup | timeout http=0 tmdb=0 curl=28 attempts=3 endpoint=search/movie | Operation timed out | retry_at=...
```

| Teşhis | Anlamı / yapılacak kontrol |
| --- | --- |
| `http=401/403`, `authentication_error` | Token/erişim reddedildi; sunucudaki ortam değişkenini ve TMDB hesabının erişimini kontrol edin. Anahtarı rapora yapıştırmayın. |
| `http=429`, `rate_limited` | TMDB sınırı; `Retry-After` süresi dolmadan istek yapılmaz. |
| `http=500/502/503/504` | Geçici API/sunucu hatası; sınırlı yeniden deneme yapılır. |
| `curl=6` | DNS çözümleme sorunu. |
| `curl=7` | TMDB sunucusuna bağlantı kurulamıyor. Ağ/proxy/erişim iznini kontrol edin. |
| `curl=28`, `timeout` | Bağlantı/yanıt süresi doldu. CLI'da bağlantı 5, toplam istek 15 saniyedir. |
| `curl=60` | TLS sertifika/CA doğrulaması başarısız; PHP'nin CA yapılandırmasını düzeltin. TLS doğrulamasını kapatmayın. |
| `invalid_json` / `invalid_response` | API/geçit yanıtı beklenen JSON/şemada değil; film bulunamaması olarak sayılmaz. |
| `internal_error` | PHP/deployment hatası; arındırılmış istisna türü ve mesajı raporda görünür. |
| `not_found` | Başarılı aramada aday yok veya hiçbir adayın adı/yılı detaylarda doğrulanmadı; API hatası değildir. |
| `ambiguous` | Birden fazla doğrulanmış film veya güvenle tüketilemeyen aday sayısı; afiş atanmaz. |

Token, API anahtarı, Authorization başlığı, istek URL'si ve ham yanıt gövdesi loglanmaz. TMDB'nin `status_message` alanı ve cURL hata metni önce bilinen sırlar, URL'ler, Bearer/API-key desenleri ve terminal kontrol karakterlerinden arındırılır. Cache ve CLI JSON çıktısı aynı güvenli teşhisi kullanır. Eski sürümden ayrıntısı olmayan kayıtlar ilk yeniden denemede gerçek teşhis kazanır.

### Yeniden deneme ve eşleştirme davranışı

- Her API çağrısı en fazla **3 kez** denenir. Geçici bağlantı hataları, 408/425 ve geçici 5xx yanıtlarında üstel bekleme ve küçük jitter uygulanır. 401/403/404/422 gibi kalıcı hatalar körlemesine tekrarlanmaz. TLS sertifika hataları yeniden deneme veya güvenliği kapatma ile gizlenmez.
- `Retry-After` hem saniye hem HTTP tarih biçiminde okunur. İstek bütçesine sığmayan uzun bekleme kısaltılmaz; servis genelinde cooldown kaydedilir. Kimlik doğrulama hatasında 1 saat beklenir; ortam değişkenindeki credential gerçekten değişirse bu engel kaldırılabilir. Sadece credential'ın tek yönlü özeti saklanır, kendisi saklanmaz.
- CLI uzun `429` beklemelerinde varsayılan olarak toplam 120 saniyelik ek bekleme bütçesiyle otomatik bekleyip devam eder. `--max-wait=0` beklemeden raporlar; `--max-wait=600` toplam bütçeyi artırır. HTTP çağrılarının kendi kısa backoff'ları bu ek bütçeden ayrıdır. Bütçe yetmezse `deferred_by_service_cooldown` ve `retry_at` gösterilir; henüz denenmemiş filmler yanlış biçimde `not_found` sayılmaz. Eski sürümün ayrıntısız `cooldown=1` kaydı yalnızca açık `--retry-errors` ile bir kez yeniden yoklanır.
- Her PHP süreci API çağrılarını en fazla yaklaşık 4 istek/saniyeye yayar; farklı işçiler TMDB `429` yanıtında ortak SQLite cooldown'ını kullanır. Bu sayı TMDB'nin garantili kotası olarak sunulmaz.
- Türkçe ve İngilizce arama yanıtları, orijinal ad/takma adlar ve her sorguda en fazla 3 sayfa incelenir. Aynı yıl içindeki adaylar, arama kartında Türkçe ad görünmese bile detay/alternatif ad kontrolüne ulaşır. En fazla 8 adayın detayları incelenir; sınır aşılırsa rastgele seçim yapılmaz.
- Bir afişin kaydedilmesi için detay kimliği, **tam yıl** ve normalize edilmiş orijinal/Türkçe/alternatif adlardan biri doğrulanmalıdır. Popülerlik veya yaklaşık isim benzerliği tek başına yeterli değildir. İki doğrulanmış aday varsa `ambiguous`; detayları kaldırılmış bir film (404) veya doğrulanmayan ad/yıl `not_found` olur. Başarılı eşleşmede bile afiş yoksa `no_poster` kullanılır.
- Kaynak verilerdeki `Evdeki Düşman` ve `Başka Bir Sen` çelişkileri otomatik düzeltilmez. 63/63 sayısını elde etmek için yanlış film veya farklı yıl kabul edilmez.

Ek test:

```sh
php tests/tmdb-http.php
```

Bu test gerçek HTTP sınıflandırma/backoff kodunu kontrollü taşıma yanıtlarıyla çalıştırır: timeout→başarı, 5xx→başarı, kalıcı 4xx, üç kez başarısız deneme, kısa/uzun/tarih biçimli Retry-After, hatalı JSON, boyut sınırı ve token redaksiyonu. Afiş testleri alternatif adlar, iki dil, sayfalama, 16 matched/47 eski error cache'inden **yalnızca 47 hatayı** yeniden deneme, uzun rate-limit beklemesinden devam etme ve veri kaybetmeyen sütun geçişini de doğrular. Bunlar canlı TMDB üzerinde 63 filmin eşleştiği iddiası değildir.


CLI'nin kendisini de izole bir SQLite dosyasıyla sınamak için:

```sh
python3 tests/sync_cli_test.py
```

Bu test gerçek `bin/sync-posters.php` komutunu başlatır; 16/47 cache sayımını, HTTP teşhislerini, JSON/text çıktısını, token gizlemeyi, seçenekleri ve mevcut parola/listelerin değişmediğini kontrol eder. Native PHP gerektirir; bazı WebAssembly CLI sarmalayıcıları PHP'nin sıfır dışı çıkış kodunu host sürecine aktarmadığından bu kontrol GitHub Actions'taki native PHP'de esas alınır.
