# Historia zmian

Każda zmiana podnosi numer wersji w `app/version.php` (`APP_VERSION`).
Format: MAJOR.MINOR.PATCH – poprawki zwiększają PATCH, nowe funkcje MINOR.

## 1.4.0 — 2026-11-01
- Usunięto powiadomienia e-mail (wysyłka przez `mail()`/SMTP, ustawienia w panelu admina).
  Migracja 004 kasuje zapisane ustawienia poczty, w tym hasło SMTP.
- Wymaga aktualizacji bazy danych (migracja 004).

## 1.3.0 — 2026-11-01
- **Nakładka dla nauczyciela** (przycisk „Nakładka” przy kodzie): małe okno z adresem strony,
  kodem dołączenia, pozostałym czasem i liczbą widzów. W Chrome/Edge jest zawsze na wierzchu
  (Document Picture-in-Picture) – można je przeciągnąć w lewy dolny róg nad pasek zadań;
  w innych przeglądarkach otwiera się okienko w lewym dolnym rogu ekranu, nad paskiem zadań.
  Zamyka się samo po zakończeniu transmisji.
- **Zmiana serwerów STUN w trakcie transmisji**: okno z gotowymi zestawami (Google, Cloudflare,
  oba, bez STUN), własną listą i testem („Sprawdź serwery” – czy uzyskano adres publiczny).
  Uczniowie w trybie zapasowym automatycznie ponawiają wtedy połączenie płynne; połączeni nie są
  rozłączani. Nauczyciel dostaje powiadomienie, gdy uczeń przechodzi na tryb zapasowy
  (z przyciskiem „Zmień STUN”); bieżące serwery widać w statystykach.
- Poprawka: na telefonie w poziomie obraz w trybie zapasowym zasłaniał pasek z przyciskiem
  „Spróbuj połączenia płynnego”. Obraz mieści się teraz w ekranie, pasek jest zwarty na niskich
  ekranach, a opcja jest też w menu jakości.
- Wymaga aktualizacji bazy danych (migracja 003 – serwery STUN transmisji).

## 1.2.0 — 2026-11-01
- **Powiadomienia e-mail** o rozpoczęciu transmisji (Panel admina → Ustawienia → Powiadomienia e-mail):
  wyłączone / tylko pierwsza transmisja danego dnia / każda transmisja; adresaci (domyślnie wszyscy
  administratorzy); wysyłka przez `mail()` serwera albo SMTP (STARTTLS, SSL/TLS, logowanie);
  przycisk „Wyślij e-mail testowy” i status ostatniej wysyłki. E-mail wysyłany jest po starcie
  transmisji, nie opóźnia jej.
- **Wybór kodeka przy rozpoczęciu transmisji** (VP9 / H.264 / AV1 / VP8 / domyślny przeglądarki;
  kodeki niedostępne w przeglądarce są wyłączone). Kodek i jakość widoczne w historii transmisji.
- **Zamknięcie karty przez nauczyciela kończy transmisję** (po ok. 10 s – odświeżenie strony
  jej nie przerywa). W historii powód „zamknięcie karty nauczyciela”.
- **Widok ucznia nie pokazuje loginu nauczyciela** (login nie jest też wysyłany do przeglądarki ucznia).
- **Więcej statystyk w trybie zapasowym**: u ucznia klatki/s, przepływność, rozmiar i wiek klatki
  (opóźnienie), czas pobierania, liczba odebranych/pominiętych klatek, pobrane dane, błędy;
  u nauczyciela osobna sekcja (widzowie w trybie zapasowym, wysyłane klatki/s, rozmiar i czas
  wysłania klatki, szacowany ruch serwera, średnie opóźnienie u widzów) oraz statystyki każdego
  ucznia (także straty pakietów zgłaszane przez ucznia).
- Wymaga aktualizacji bazy danych (migracja 002 – kolumny kodeka/jakości, statystyk widza,
  znacznika czasu klatki i ustawienia e-mail).

## 1.1.0 — 2026-11-01
- **Wyższa jakość obrazu**: przechwytywanie w pełnej rozdzielczości ekranu (do 4K, wcześniej
  maks. Full HD), wyższe przepływności (do 6 Mb/s na widza), kodek **VP9** domyślnie (ostrzejszy
  tekst; do wyboru w Panelu admina: VP9 / H.264 / AV1 / VP8 / domyślny przeglądarki) i szybszy start
  w dobrej jakości.
- Cztery presety jakości: *Maks. ostrość* (do 2560 px), *Zrównoważona*, *Ruch i wideo*, *Oszczędna*.
  **Nauczyciel zmienia jakość w trakcie transmisji** bez przerywania połączeń.
- **Widz wybiera swoją jakość** (Najlepsza / Średnia / Niska – tylko dla swojego połączenia)
  oraz rozmiar **100% (piksel w piksel)** lub dopasowanie do okna.
- **Statystyki**: u nauczyciela łączne wysyłanie, źródło, wysyłany obraz, kodek, opóźnienie,
  ograniczenie jakości (procesor/łącze) z podpowiedzią oraz dane każdego widza; u widza
  rozdzielczość, klatki/s, przepływność, kodek, straty pakietów, jitter, opóźnienie, typ połączenia.
- Tryb zapasowy: klatki w wyższej rozdzielczości i jakości JPEG.
- Tytuł transmisji generuje się automatycznie z daty i godziny (można go zmienić).
- Strona dla uczniów nie pokazuje linku do logowania nauczycieli (logowanie: `domena/login/`).
- Bez zmian w bazie danych.

## 1.0.0 — 2026-11-01
- Pierwsza wersja: udostępnianie ekranu na żywo podczas zajęć (WebRTC z sygnalizacją przez PHP,
  bez WebSocketów), tryb zapasowy z klatkami przez serwer, jedna transmisja naraz, maks. 180 minut
  i 25 widzów, dostęp dla uczniów na stronie głównej po wpisaniu 6-cyfrowego kodu (bez logowania),
  kod QR i widok kodu na rzutnik, lista widzów z rozłączaniem, przedłużanie transmisji,
  historia transmisji.
- Z aplikacji tablica-php przeniesiono: logowanie/rejestrację z 2FA (TOTP), role administrator /
  użytkownik, panel admina (użytkownicy, ustawienia, języki, aktualizacje, kopia bazy), instalator
  i aktualizator w stylu WordPress, migracje bazy, prefiks tabel `ssliveXYZ_`, GitHub Action
  budujący paczkę ZIP.
