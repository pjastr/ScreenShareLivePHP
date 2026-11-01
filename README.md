# ScreenShare Live PHP

Udostępnianie ekranu **na żywo** podczas zajęć lekcyjnych. Aplikacja jest napisana w czystym
**PHP + MySQL/MariaDB**, bez Composera, bez Node.js na serwerze i bez WebSocketów, więc działa
na zwykłym hostingu współdzielonym. Licencja: **MIT**.

## Jak to działa

| Adres | Dla kogo |
|---|---|
| `https://domena/` | **uczniowie**: wpisują 6-cyfrowy kod i oglądają, bez logowania |
| `https://domena/?code=123456` | link z kodem (np. z kodu QR), wpuszcza od razu |
| `https://domena/login/` | logowanie nauczycieli i administratorów |
| `https://domena/panel/` | panel: udostępnianie ekranu, profil, panel admina |

1. Nauczyciel loguje się, klika **Rozpocznij udostępnianie** i wybiera ekran, okno lub kartę.
2. Aplikacja tworzy transmisję z **6-cyfrowym kodem**. Kod widzi nauczyciel, który prowadzi
   transmisję, oraz administrator (Panel admina → Przegląd). Przycisk **Pokaż na ekranie**
   wyświetla adres, kod i kod QR na pełnym ekranie, np. na rzutniku.
3. Uczniowie wchodzą na stronę główną, wpisują kod (opcjonalnie imię) i oglądają.

### Zasady i limity

- **Tylko na żywo**: obraz nie jest nagrywany ani zapisywany. Kończy się wraz z transmisją.
- W danej chwili może trwać **tylko jedna transmisja**. Pilnuje tego unikalny indeks w bazie,
  także przy równoczesnych próbach startu.
- Maksymalny czas transmisji to **180 minut**. Nauczyciel wybiera czas przy starcie (domyślnie
  90 min) i może przedłużać po 15 min, ale nigdy ponad 180 min od rozpoczęcia. Po czasie
  serwer sam kończy transmisję.
- Transmisję może oglądać **maksymalnie 25 widzów** jednocześnie. Kolejne osoby widzą komunikat
  „Brak wolnych miejsc” i wchodzą automatycznie, gdy ktoś wyjdzie.
- Admin może limity tylko **zmniejszyć** (Panel admina → Ustawienia → Transmisje). Wartości 180 min
  i 25 widzów są stałymi w `app/lib/Live.php`.
- Zamknięcie lub odświeżenie strony przez nauczyciela nie przerywa transmisji od razu. Uczniowie
  czekają, a nauczyciel wznawia ją jednym kliknięciem. Bez nadawcy przez 90 s transmisja się kończy.
- Ochrona kodu: po 10 błędnych próbach z jednego adresu IP następuje blokada na 10 minut.
  Nauczyciel może wygenerować nowy kod bez rozłączania oglądających i może rozłączyć wybranego widza.

### Technika

- Obraz płynie **bezpośrednio z przeglądarki nauczyciela do uczniów** przez **WebRTC**
  (natywne API przeglądarki). Serwer PHP przekazuje tylko krótkie wiadomości sygnalizacyjne
  (offer/answer/ICE) zwykłymi żądaniami HTTP.
- **Tryb zapasowy**: gdy sieć ucznia blokuje WebRTC, uczeń po ~15 s automatycznie dostaje obraz
  przez serwer (klatka JPEG co ~1 s). Nauczyciel wysyła klatki tylko wtedy, gdy ktoś ich potrzebuje.
  Tryb można wyłączyć w ustawieniach.
- Presety jakości: *Tekst i slajdy* (10 kl./s), *Zrównoważona* (15 kl./s), *Ruch i wideo* (30 kl./s).
  Przy 25 widzach komputer nauczyciela koduje obraz 25 razy, a łącze wysyła 25 strumieni
  (ok. 1–2,5 Mb/s każdy). Na słabszym sprzęcie lub łączu wybierz *Tekst i slajdy*.
- Opcjonalnie dźwięk karty lub systemu (Chrome/Edge; Firefox udostępnia tylko obraz).
- **Serwery STUN/TURN** ustawia się w Panelu admina. Domyślny publiczny STUN wystarcza w szkolnej
  sieci i w większości sieci domowych. Gdy uczniowie łączą się z sieci z restrykcyjną zaporą,
  pomaga własny serwer TURN (np. coturn) albo tryb zapasowy.

## Wymagania

- **HTTPS**: przeglądarki pozwalają udostępniać ekran tylko na stronach `https://`
  (wyjątek: `localhost`).
- PHP **8.1+** z rozszerzeniami `pdo_mysql` i `mbstring`
  (opcjonalnie `zip` i `curl`, do aktualizacji jednym kliknięciem)
- MySQL **5.7+** lub MariaDB **10.2+**
- Apache (plik `.htaccess` jest dołączony) lub inny serwer WWW z PHP
- Nauczyciel: Chrome, Edge lub Firefox na komputerze. Uczniowie: dowolna nowoczesna przeglądarka,
  także na telefonie lub tablecie.

## Instalacja (jak WordPress)

1. Utwórz pustą bazę danych albo użyj istniejącej. Tabele dostaną własny prefiks.
2. Wgraj wszystkie pliki na serwer, np. przez FTP.
3. Otwórz adres aplikacji w przeglądarce. Uruchomi się instalator (`install.php`), który:
   - sprawdzi wymagania serwera (także HTTPS),
   - poprosi o dane bazy i pozwoli **sprawdzić połączenie**,
   - poprosi o nazwę aplikacji i konto administratora.
4. Gotowe. Instalator sam:
   - wylosuje wolny prefiks tabel **`ssliveXYZ_`** (XYZ to 3 losowe cyfry; sprawdza, czy prefiks nie jest zajęty),
   - utworzy tabele i zapisze `config.php`. Jeśli serwer nie pozwala na zapis, wyświetli plik
     do pobrania i wgrania ręcznie.

## Aktualizacja

Bez ręcznych zmian w bazie danych. Do wyboru:

- **Panel admina → Aktualizacje → Sprawdź aktualizacje → Zainstaluj**: pobiera wydanie z GitHub
  Releases repozytorium ustawionego w panelu (domyślnie `pjastr/ScreenShareLivePHP`),
- **wgranie paczki ZIP** nowej wersji w tym samym miejscu,
- wgranie nowych plików przez FTP z nadpisaniem starych (nie usuwaj `config.php`).

Po podmianie plików aplikacja sama wykryje nowszy schemat bazy i pokaże administratorowi ekran
**„Wymagana aktualizacja bazy danych”**. Wystarczy kliknąć jeden przycisk. Przed aktualizacją
można pobrać kopię bazy (Panel admina → Aktualizacje → Pobierz kopię bazy).

### Dla programistów: nowa wersja

1. Zwiększ `APP_VERSION` w `app/version.php`.
2. Zmiany w bazie dodaj jako nowy plik `migrations/NNN_opis.php` (kolejny numer). Plik zwraca
   funkcję wykonującą zapytania. Nazwy tabel zapisuj jako `{nazwa}`, a prefiks zostanie dodany
   automatycznie.
3. Zrób commit na `main`. GitHub Action (`.github/workflows/build-zip.yml`) po każdym commicie:
   - sprawdza składnię wszystkich plików PHP,
   - buduje paczkę `screensharelive-php-X.Y.Z.zip` (bez `config.php`, `tools/` i plików
     tymczasowych) i udostępnia ją jako artefakt w zakładce **Actions**,
   - jeśli wersja z `APP_VERSION` nie ma jeszcze tagu `vX.Y.Z`, tworzy **Release** z tym ZIP-em.
     Wtedy zainstalowane aplikacje zobaczą aktualizację w panelu admina.

## Konta i panel administratora

- Logowanie i rejestracja (rejestrację można wyłączyć w panelu admina), role **administrator /
  użytkownik (nauczyciel)**, weryfikacja dwuetapowa **2FA (TOTP)** z kodem QR i kodami zapasowymi,
  opcjonalne wymuszanie 2FA, blokada po nieudanych próbach logowania.
- Wejście do panelu admina wymaga 2FA i świeżego kodu.
- Panel: przegląd z **bieżącą transmisją** (kod, widzowie, przycisk zakończenia),
  **historia transmisji**, użytkownicy, ustawienia (limity, imię widza: brak / opcjonalne /
  wymagane, jakość, tryb zapasowy, STUN/TURN), języki (wbudowane PL i EN, dodawanie kolejnych
  i tłumaczenie w przeglądarce), aktualizacje, kopia zapasowa bazy (.sql).
- Nowoczesny interfejs z trybem jasnym i ciemnym, działa w **Chrome i Firefox**.

## Struktura

```
index.php        – strona główna dla uczniów (kod → odtwarzacz)
login/, panel/   – wejścia do logowania i panelu (wspólny router app/router.php)
api.php          – API JSON: sygnalizacja WebRTC, klatki trybu zapasowego
install.php      – instalator
app/             – kod PHP (lib/, pages/, views/, lang/)
migrations/      – migracje schematu bazy
assets/          – CSS, JS (share.js – nadawca, live.js – widz), ikony, biblioteki
storage/         – pliki tymczasowe aktualizatora
tools/           – skrypty pomocnicze (nieużywane na serwerze)
```

## Bezpieczeństwo

Hasła: `password_hash`. Ochrona CSRF, ciasteczka sesji `HttpOnly`/`SameSite`, nagłówki CSP,
limit prób logowania i wpisywania kodu. Widz dostaje losowy token ważny tylko dla bieżącej
transmisji. Katalogi `app/`, `migrations/`, `storage/` są chronione przez `.htaccess`
(na serwerze nginx zablokuj do nich dostęp w konfiguracji).

## Licencja

MIT, zob. `LICENSE`. Komponenty zewnętrzne (wszystkie na licencji MIT): `THIRD-PARTY-NOTICES.md`.
