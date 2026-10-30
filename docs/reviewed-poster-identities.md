# İncelenmiş TMDB kimlikleri — 2026-10-30

| Katalog kaydı | TMDB kimliği | Resmi kaynak | Doğrulama |
| --- | --- | --- | --- |
| La La Land (2016) | 313369 | https://www.themoviedb.org/movie/313369-la-la-land | Başlık/yıl, Damien Chazelle, Ryan Gosling ve Emma Stone resmi sayfada doğrulandı. Türkçe adı Aşıklar Şehri de kimlik kontrolünde kabul edilir. |
| It (2017) | 346364 | https://www.themoviedb.org/movie/346364-it | Başlık/yıl, Andy Muschietti, Bill Skarsgård ve Finn Wolfhard resmi sayfada doğrulandı. Türkçe O adı kabul edilir. |
| Terrifer / Terrifier (2016) | 420634 | https://www.themoviedb.org/movie/420634-terrifier | Başlık, Damien Leone, David Howard Thornton ve Jenna Kanell resmi sayfada doğrulandı. Ana sayfa yılı 2018 olduğundan yalnızca yılı karşılaştırmak yeterli değildir. |

Terrifier resmi gösterim tarihleri: https://www.themoviedb.org/movie/420634-terrifier/releases — ABD 10/15/2016 Premiere, Telluride Horror Show Film Festival; 01/25/2018 Theatrical (limited). API alanı: https://developer.themoviedb.org/reference/movie-release-dates — Premiere türü `1`.

Bu kimlikler belirsiz arama sonuçlarından tahmin edilmedi. Resmi HTML sayfaları başlık, yıl ve oyuncu/yönetmen bilgileri için okundu; afiş URL'si koda aktarılmadı. Çalışma anında aynı kimlik ve kanıtların API'den yeniden doğrulanması zorunludur.

Canlı API credential bu geliştirme ortamında yok. Kullanıcının 54 matched, 5 ambiguous, 4 not_found içeren SQLite dosyası bu konuşmada mevcut değil; bu nedenle hangi dokuz kaydın eksik olduğu sayıdan tahmin edilmez. Diğer altı kimlik için kanıt olmadan kayıt eklenmemiştir. `php bin/sync-posters.php --unresolved --json` çıktısı gerçek listeyi verir.
