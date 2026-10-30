# Güvenlik incelemesi

Tarih: 2026-10-30. Kapsam: son geliştirme dalındaki PHP, SQLite, HTTP formları ve TMDB entegrasyonu. Statik kod incelemesi ve izole otomatik testlerdir; bağımsız sızma testi, canlı TLS denetimi veya güvenlik sertifikası değildir. Kritik düzeyde doğrulanmış bir bulgu tespit edilmedi; bu, sistemde kritik açık bulunamayacağı garantisi değildir.

## Bulgular ve kararlar

| Önem | Bulgu | Durum / kanıt |
| --- | --- | --- |
| Yüksek | Giriş kotası kontrolü ile artırımı ayrı işlemlerdi; eşzamanlı istekler kontrolü geçebiliyordu. | Düzeltildi. `auth-throttle.php` BEGIN IMMEDIATE ile kontrol ve rezervasyonu atomik yapar; hash işlemi kilit dışında çalışır. Native PHP testinde 20 eşzamanlı süreç için tam 10 izin/10 ret beklenir. |
| Yüksek (hesap erişilebilirliği) | E-posta genelinde kota, saldırganın kendi IP'sinden kurban hesabını farklı IP'lerde de kilitlemesine izin veriyordu. Başarılı girişler de küçük kotayı tüketiyordu. | Düzeltildi. IP+hesap çifti ve ayrı IP bütçesi kullanılır. Başarılı giriş yalnızca çift sayacını temizler. Başka IP'deki kurbanın erişimi kesilmez. Dağıtık credential-stuffing için ek gateway/izleme gerekir. |
| Yüksek, yapılandırmaya bağlı | SQLite yolu public dizine verilirse dosya indirilebilir. Repo kökü web kökü olarak sunulursa özel dosyalar açığa çıkabilir. | Kod artık SQLite'ın gerçek dizinini ve mevcut dosya symlink hedefini public köke karşı reddeder; Unix sistemlerinde yeni dosya/dizinler kısıtlı umask/mode ile oluşturulur; Windows NTFS ACL izinleri ayrıca ayarlanmalıdır. Doğru document root HTTP testinde özel yollar 404 verir. **Gerçek hosting document root'unun yanlış ayarlanması yalnızca PHP koduyla tamamen önlenemez; yayın engelidir.** |
| Yüksek, yayın ortamına bağlı | Uygulama production modunda düz HTTP oturum açmayı zorunlu olarak engellemiyordu. | `APP_ENV=production` ile TLS yoksa 426 ve oturum çerezi oluşturulmadan ret. Güvenilmeyen X-Forwarded-Proto kabul edilmez; TLS proxy için sunucu tarafında APP_HTTPS gerekir. Secure çerez ve HSTS test edilir. Gerçek sertifika/proxy henüz incelenmedi. |
| Orta | Sadece hareketsizlik süresi vardı; aktif oturum sınırsız uzayabiliyordu. | 2 saat idle + 8 saat mutlak sınır eklendi. Eski aktif oturumlar ilk istekte başlangıç zamanını alır; kullanıcı kayıtları değişmez. |
| Orta | Global hata kaydı ham istisna ve stack argümanlarını içerebiliyordu. | HTTP hataları yalnızca hata referansı ve istisna sınıfını loglar; mesaj, stack, URL, parola veya token içermez. Kullanıcıya genel hata döner. TMDB CLI'nin ayrı arındırılmış teşhis sistemi korunur. display_errors web uçlarında kapatılır. |
| Orta | Kısa parolalar yeni hesaplarda kabul ediliyordu. | Yeni kayıtta en az 15 Unicode karakter / en fazla 72 bayt; bcrypt sınırı sessizce aşılmaz. Mevcut hesaplar eski parolalarıyla giriş yapabilir; toplu hash/parola dönüşümü yok. |
| Orta, kalan | Kayıtta e-postanın kullanılabilirliği ve bazı parola-hash süre farkları hesap varlığını açığa çıkarabilir. | Tam çözüm için e-posta doğrulamalı kayıt akışı gerekir. Giriş hata mesajı geneldir; dummy hash doğrulaması korunur. "Hesap keşfi tamamen engellendi" iddiası yoktur. |
| Orta, kalan | Public afiş isteği gerektiğinde dış API işi başlatır; yavaş TMDB yanıtları PHP işçilerini meşgul edebilir. | Mevcut süre/yanıt boyutu, lease, cache, Retry-After ve servis cooldown sınırları korunur. Kuyruk/cron, HTTP rate limiting ve yük testi yayın ölçeğine göre yapılmalı. |
| Düşük / bakım | Şema kontrolü bağlantı sırasında; global fonksiyonlar ve view içinde basit koşullar var. | Kontrollü modüllere ayrıldı; tüm mimari framework'e taşınmadı. İleri aşamada explicit migration sürümleri, namespace ve statik analiz değerlendirilebilir. |

## Denetlenen korumalar

- **SQL injection:** kullanıcı değerleri PDO parametreleriyle bağlanır. Dinamik tablo/sütun isimleri kullanıcıdan alınmaz. Arama LIKE wildcard'larını kaçırır. SQL payload regresyonları mevcut.
- **XSS:** HTML/attribute çıktıları `e()`/htmlspecialchars ile kaçırılır. Tavsiye açıklaması da aynı yoldan geçer. CSP inline JS'yi ve harici scriptleri açmaz.
- **CSRF:** durum değiştiren işlemler POST + oturum tokeni gerektirir. Başka kullanıcının tokeni, eksik token ve eski token reddedilir. GET listeleri değiştirmez.
- **IDOR:** kullanıcı kimliği POST/GET'ten alınmaz; listeler ve öneriler oturum kullanıcısı üzerinden sorgulanır. İki hesapla okuma/yazma izolasyonu test edilir.
- **Parolalar:** password_hash/password_verify; hash alanı TEXT. Yeni algoritma mevcut hashleri topluca değiştirmez. Önceden var olan geçerli-login rehash davranışı gerektiğinde devam eder.
- **Oturum:** strict mode, cookie-only, HttpOnly, SameSite=Lax, HTTPS'te Secure, girişte session ID ve CSRF yenileme; çıkışta session ve çerez iptali.
- **TMDB sırları:** sadece ortam değişkenleri. Web HTML/JS'sinde credential yok. URL/Authorization/ham yanıt loglanmaz; mevcut redaksiyon testleri korunur.
- **Veri:** öneri işlemleri yalnızca SELECT yapar. Güvenlik değişikliği mevcut auth_attempts tablosunu kullanır; movies/users/user_movies tablolarını yeniden oluşturmaz. İzole testler satır bazında korumayı doğrular.

## Yayın öncesi açık işler

HTTPS/sertifika ve proxy ayarları, document root, dosya/backup erişim izinleri, güncel PHP, web sunucusu body/istek limitleri, korumalı log rotasyonu ve tutarlı backup'tan geri yükleme gerçek ortamda doğrulanmalı. PHP geliştirme sunucusu internete açılmamalı. Tokenler repoya veya ekran görüntülerine konulmamalı. TMDB anahtarı olmadan 63/63 afiş doğrulaması yapılmış sayılmaz.

Parola değiştirme/sıfırlama, e-posta doğrulama, tüm oturumları iptal, MFA ve hesap silme bu sürümde yok. Bunlar varmış gibi README/CV'de sunulmamalı. Hesap/IP kotaları (10 çift, 120 login IP, 20 kayıt IP / 15 dakika) bir başlangıç politikasıdır; paylaşımlı ağ ve dağıtık saldırı davranışı gerçek trafikle ayarlanmalıdır. IP için yalnızca REMOTE_ADDR kullanılır; proxy header'ları otomatik güvenilir sayılmaz.

Kaynaklar: [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [OWASP Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html), [PHP password_hash](https://www.php.net/manual/en/function.password-hash.php).
