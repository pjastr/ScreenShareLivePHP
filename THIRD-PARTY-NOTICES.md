# Komponenty zewnętrzne / Third-party notices

ScreenShare Live PHP jest udostępniana na licencji MIT (plik `LICENSE`).
Aplikacja zawiera wyłącznie poniższe komponenty firm trzecich – oba na licencji MIT, która
pozwala na swobodne użycie, modyfikację i dystrybucję, także komercyjną. Przesyłanie obrazu
korzysta z wbudowanych w przeglądarkę interfejsów WebRTC i Screen Capture (bez bibliotek).
Obsługa TOTP (2FA), sygnalizacja WebRTC, instalator, aktualizator i pozostały kod są własną
implementacją projektu (MIT).

Nie są używane żadne zewnętrzne czcionki (tylko czcionki systemowe) ani usługi CDN –
wszystkie pliki są serwowane lokalnie. Jedyną domyślną usługą zewnętrzną jest publiczny
serwer STUN (`stun:stun.l.google.com:19302`), który pomaga przeglądarkom ustalić adres sieciowy;
nie przechodzi przez niego obraz. Można go zmienić lub usunąć w Panelu admina → Ustawienia.

| Komponent | Wersja | Plik | Licencja |
|---|---|---|---|
| QR Code Generator for JavaScript (Kazuhiko Arase) | 1.4.4 | `assets/vendor/qrcode.js` | MIT |
| Tabler Icons (Paweł Kuna) | 3.19.0 | `assets/js/icons.js`, `app/lib/icons_data.php` (wybrane ikony) | MIT |

„QR Code” jest zastrzeżonym znakiem towarowym DENSO WAVE INCORPORATED.

---

## QR Code Generator for JavaScript — MIT

Copyright (c) 2009 Kazuhiko Arase — http://www.d-project.com/

Licensed under the MIT license: http://www.opensource.org/licenses/mit-license.php
(treść licencji MIT jak wyżej).

## Tabler Icons — MIT

Copyright (c) 2020-2024 Paweł Kuna — https://tabler.io/icons

Licensed under the MIT license (treść licencji MIT jak wyżej).
