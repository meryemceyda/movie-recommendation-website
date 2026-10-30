# NCFilm — Movie Catalog & Personal Lists

PHP ve SQLite ile geliştirilen film kataloğu. 63 film, çoklu kategori filtreleme, film adıyla arama, kullanıcı hesapları ve kullanıcıya özel film listeleri içerir.

## Özellikler

- Film detayları: yıl, türler, başrol ve konu; mevcut 6 yerel poster korunur.
- Kayıt, giriş ve POST ile çıkış; hesaplar benzersiz e-posta adresi kullanır.
- **İzlenecekler**, **İzlendi**, **Favoriler** listeleri; listelere ekleme ve çıkarma.
- İzlendi/İzlenecekler durumları birbirinin yerine geçer; Favoriler bağımsızdır.
- Liste erişimi oturumdaki kullanıcı kimliğiyle sınırlandırılır.
- PDO prepared statements, parola hashleme, CSRF tokenleri, girişte oturum kimliği yenileme, HttpOnly/SameSite çerezleri ve veritabanında tutulan giriş denemesi sınırı.
- 2 saat hareketsizlikten sonra oturum sona erer. Kimlik doğrulamada e-posta veya IP başına 15 dakikada en fazla 10 deneme yapılabilir; başarılı denemeler de kotaya dahildir.

**Önerilenler kişiselleştirilmiş değildir:** mevcut altı filmlik sabit seçkidir. Bu sürüm makine öğrenmesi veya kullanıcı davranışına dayalı öneri algoritması içermez.

## Gereksinimler

- PHP **8.1+** ve `PDO`, `pdo_sqlite`, `session` eklentileri. CI PHP 8.3 ile çalışır.
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
