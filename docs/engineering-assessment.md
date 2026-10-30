# Yazılım mühendisliği değerlendirmesi

## Tamamlananlar

- Favoriler/izleme geçmişinden ağırlıklı tür profili ve kosinüs benzerliği üreten gerçek içerik tabanlı öneri akışı. Sabit ana sayfa seçkisi yerine kullanıcıya göre sıralama ve kısa neden açıklaması.
- Saf sıralama fonksiyonu ile PDO veri erişiminin ayrılması; POST işlemleri ve HTML form yardımcılarının index.php'den ayrılması.
- Atomik login rezervasyonu, IP/hesap kota izolasyonu, başarılı girişte çift sayacının sıfırlanması, mutlak session ömrü, production HTTPS kapısı, güvenli hata olayları ve public SQLite yolunun reddi.
- Yeni parolalarda en az 15 karakter/72 bayt üst sınırı. Mevcut hesaplarda zorunlu parola sıfırlama veya toplu hash dönüşümü yok.
- Kaynak verileri değiştirmeyen tutarlı backup komutu, tek test komutu, agregat offline öneri değerlendirmesi ve portföy README'si.
- Mevcut 63 film, liste durum geçişleri, hesaplar ve TMDB cache/kimlik/retry entegrasyonu korunur. Öneri işlevi cache'e dahi yazmaz.

## Test kanıtı ve sınırları

Test komutları `README.md` ve `.github/workflows/php.yml` içindedir. GitHub Actions'ta PHP 8.3 native çalıştırma; yerel geliştirme ortamında uygun PHP WebAssembly birim testleri ve PHP 8.3 HTTP köprüsü kullanılır. Native exit code, süreçler arası SQLite kilidi ve production ortam simülasyonu için CI sonucu esas alınır.

Kapsam: kayıt/giriş/çıkış, eski parola doğrulama, SQL/XSS/CSRF girdileri, IDOR/hesap izolasyonu, listeler, numerik öneri puanları, eşitlik sıralaması, cold start, eksik türler, boş/tümü izlenmiş katalog, hesaplar arası farklı profiller, veri koruma, TMDB hataları/redaksiyon/kimlik, 54 cache satırının korunması, gerçek CLI ve backup bütünlüğü. Limiter testi bir start bariyeri üzerinden 20 PHP sürecinde tam 10 izin/10 ret ister. Production testi gerçek TLS değil, sunucu ortam ayarı simülasyonudur.

Canlı kullanıcının veritabanı/API anahtarı bu ortamda yoktur. Burada gerçek kişisel listeler üzerinde kalite değerlendirmesi, 63/63 canlı afiş kontrolü, tarayıcı görsel QA, gerçek TLS/hosting denetimi veya yük testi yapılmış değildir. Testlerin yeşil olması bütün açıkların yokluğu anlamına gelmez. CI test sonuçları PR'ın Checks bölümünden incelenmelidir; bu belge uydurma test sayısı veya performans metriği yayınlamaz.

## Algoritma ve ölçüm

Her tercih filminin ağırlığı (favori 3, izlenen 1, ikisi birlikte 4) kendi tür sayısına bölünür. Adayların ikili tür vektörü ile oluşan profilin kosinüs benzerliği hesaplanır. İzlenen/favori filmler elenir. Pozitif benzerlikler altı ondalıkla sıralanır, eşitlik film ID'siyle çözülür; açıklama en çok katkı veren iki türü belirtir. Eksik veri için deterministik tür kapsama seçkisi kullanılır. Eğitim veya dış ML servisi yoktur; bu yine de kişiselleştirilmiş bir content-based recommender'dır.

`bin/evaluate-recommendations.php`, en az üç tercih filmi olan profillerde leave-one-film-out Hit Rate@6/MRR@6 üretir. Hedef filmin tüm etkileşimleri profilden çıkarılır; kimlikler dışa aktarılmaz. Pozitif listeler, küçük katalog ve eşitlik kuralları yanlılık yaratır; ölçüm online memnuniyet kanıtı değildir. Gerçek veri olmadan skor uydurulmaz; sıfır vakada null metrik döner.

## Kalan işler

1. Gerçek ortam document root/TLS/proxy/Secure cookie ve backup restore doğrulaması. Güncel PHP, log rotasyonu, gövde/istek limitleri ve disk erişimi.
2. E-posta doğrulama, güvenli parola değiştirme/sıfırlama, global oturum iptali ve hesap silme. Kayıtta hesap keşfi ve dağıtık credential-stuffing için ek kontrol.
3. Gerçek kalan afiş raporunun incelenmesi; metadata çelişkilerinin kanıtla çözülmesi. Uydurma afiş veya film yılı değişikliği yapılmaz.
4. Gerçek tarayıcıda masaüstü/mobil erişilebilirlik ve görsel kontrol; gizli veri içermeyen ekran görüntüleri.
5. Puanlama/olumsuz geri bildirim ve daha zengin film özellikleri; ancak önce gerçek veriyle öneri değerlendirmesi.
6. Trafik arttığında afiş kuyruğu, sınırlı eşzamanlılık, cache yenileme işi, explicit versioned migrations ve statik analiz. SQLite yazma sınırları ölçülmeden ölçek iddiası yok.

## CV için savunulabilir kazanımlar

“PHP ve SQLite ile kullanıcıya özel film listeleri ve açıklanabilir içerik tabanlı öneri sistemi geliştirdim; favori/izleme sinyallerini ağırlıklı tür profiline dönüştürüp kosinüs benzerliğiyle deterministik sıralama uyguladım. PDO veri erişimini saf sıralama mantığından ayırdım; CSRF/session korumaları, atomik giriş sınırlaması ve TMDB hata/önbellek akışlarını regresyon testleriyle GitHub Actions'ta doğruladım.”

Bu ifade uygulanan teknikleri anlatır; yüksek doğruluk, büyük kullanıcı ölçeği, eğitilmiş yapay zekâ modeli, kusursuz güvenlik veya canlı 63/63 afiş iddiası içermez. Başvuruda kodu ve testlerdeki tercihleri açıklayabilmek gerekir.

## Hazırlık kararı

**Portföy:** incelenebilir kod, deterministik algoritma, test kapsamı ve dürüst sınırlamalar mevcut. PR doğrulaması ve gerçek ekran görüntüleriyle sergilenebilir; önceki açık PR'lar dahil dallar dikkatle entegre edilmelidir.

**Canlı yayın:** koşullu; yukarıdaki gerçek ortam kontrolleri tamamlanmadan hazır ilan edilmez. PHP geliştirme sunucusunu internete açmak yayın çözümü değildir. Ana dala otomatik merge veya canlı deploy yapılmaz.
