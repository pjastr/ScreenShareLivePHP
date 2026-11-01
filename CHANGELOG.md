# Historia zmian

Każda zmiana podnosi numer wersji w `app/version.php` (`APP_VERSION`).
Format: MAJOR.MINOR.PATCH – poprawki zwiększają PATCH, nowe funkcje MINOR.

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
