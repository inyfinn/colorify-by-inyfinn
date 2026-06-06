# Colorify by INYFINN

Personalizacja wyglądu panelu WordPress (`wp-admin` + strona logowania) dla projektu **Colorify**.

Autor: [INYFINN](https://inyfinn.art) · Wersja wtyczki: **1.0.2**

---

## Szybki start

| Tryb | Gdzie zmieniać kolory |
|------|------------------------|
| **Per użytkownik** (domyślny) | **Użytkownicy → Profil** → sekcja *Kolory administracyjne* |
| **Globalne** | **Ustawienia → Colorify** → przełącznik *Globalne* |

Przycisk **„Zapisz ustawienia”** w karcie *Dostrojenie kolorów* zapisuje schemat, paletę i dostrojenie bez przewijania do góry formularza profilu.

---

## Instalacja

Na tej instalacji Colorify działa **wyłącznie jako wtyczka** (`wp-content/plugins/colorify-by-inyfinn/`). Moduł MU (`colorify-loader.php` + `mu-plugins/colorify/`) został usunięty.

Wtyczka nadal wykrywa przypadkowe równoległe ładowanie MU (`COLORIFY_MU_MODULE`, `colorify-loader.php`) i wtedy nie startuje — żeby uniknąć duplikatu stylów.

---

## Struktura plików

```
colorify-by-inyfinn/
├── colorify-by-inyfinn.php      # Bootstrap, assety, login branding, hooki admina
├── README.md                    # Ten dokument
├── includes/
│   ├── colorify-admin-schemes.php  # Rdzeń: schematy, tokeny CSS, paleta, dostrojenie
│   ├── colorify-scope.php            # Zakres globalny vs per-user (opcje witryny)
│   └── class-colorify-settings.php   # Panel Ustawienia → Colorify
└── assets/
    ├── colorify-branding.css       # Login + ogólny branding admina
    ├── colorify-admin-colors.css   # Placeholder schematów WP (rejestracja admin_color)
    ├── colorify-admin-overrides.css        # UI profilu: paleta, dostrojenie, modal, przycisk Zapisz
    ├── colorify-admin-appearance.js # Live preview, suwaki, zapis, dark/light switch
    ├── colorify-settings.css         # Styl panelu ustawień wtyczki
    └── inyfinn-logo-okrag.svg        # Fallback favicon / logo
```

### Co jest do czego

| Plik | Odpowiedzialność | Bezpiecznie edytować? |
|------|------------------|----------------------|
| `colorify-by-inyfinn.php` | Ładowanie modułów, enqueue assetów, stopka, switch dark/light | Tak (ścieżki, wersje) |
| `colorify-admin-schemes.php` | **Najważniejszy** — definicje ~40 schematów, HSL tuning, PHP→JS data | Ostrożnie — testuj wszystkie schematy |
| `colorify-scope.php` | `user` / `global` scope, opcje `colorify_global_*` | Tak |
| `class-colorify-settings.php` | UI ustawień wtyczki | Tak |
| `colorify-admin-appearance.js` | Cała logika podglądu na żywo | Tak — bump `COLORIFY_APPEARANCE_JS_VERSION` |
| `colorify-admin-overrides.css` | Layout kart profilu, modal dostrojenia, **przycisk Zapisz** | Tak — bump `COLORIFY_ADMIN_OVERRIDES_VER` |
| `colorify-branding.css` | Kolory loginu, admin bar, formularze (duży plik) | Ostrożnie — regresje UI |

---

## Jak to działa (architektura)

```
┌─────────────────────────────────────────────────────────────────┐
│  PHP: colorify_admin_scheme_definitions()                     │
│       → rejestracja admin_color (WP core)                       │
│       → colorify_admin_get_resolved_scheme() + tuning         │
│       → colorify_admin_tokens_from_scheme() → CSS variables   │
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│  JS: colorify-admin-appearance.js                               │
│       → applyAppearance(scheme, mode) na :root                    │
│       → suwaki dostrojenia (−90…+90)                              │
│       → własna paleta 4 kolory (colorify-custom)                        │
└────────────────────────────┬────────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────────┐
│  Zapis                                                            │
│   • Profil użytkownika: user_meta (admin_color, colorify_admin_*)       │
│   • Globalne: wp_options (colorify_global_*)                      │
│   • AJAX: tylko tryb dark/light (colorify_save_admin_appearance)          │
│   • Przycisk Zapisz: submit formularza profilu / ustawień         │
└─────────────────────────────────────────────────────────────────┘
```

### User meta / opcje

| Klucz | Zawartość |
|-------|-----------|
| `admin_color` | Klucz schematu (`colorify-lime`, `colorify-custom`, …) |
| `colorify_admin_appearance` | `dark` lub `light` |
| `colorify_admin_custom_colors` | `{ bg, bg2, accent, accent2 }` HEX |
| `colorify_admin_custom_tuning` | `{ dark: {…}, light: {…} }` suwaki |
| `colorify_settings_scope` | `user` lub `global` |
| `colorify_global_*` | Te same dane w trybie globalnym |

---

## Schematy kolorów

- **~40 presetów** w grupach: zielone (`colorify-*`), ciepłe, fioletowe, ziemiste (Bursztyn, Kamień, Szmaragd).
- **Własna paleta** (`colorify-custom`): 4 kolory użytkownika.
- **Dostrojenie** (−90…+90): jasność i nasycenie tła/akcentów, osobno dark/light.
- Wartości **> +50** oznaczone wizualnie (czerwone) — ostrzeżenie przed ekstremalnymi korektami.
- **Dark mode**: niektóre schematy mają przyciemnione tło (−15% HSL); wyjątki: zielone, Bursztyn, Kamień, Szmaragd.

### Czego NIE zmieniać bez powodu

1. **Klucze schematów** (`colorify-lime`, `colorify-amber`, …) — zapisane w `user_meta` użytkowników. Stare `dk-*` / `vp-*` mapuje `colorify_admin_normalize_scheme_key()`.
2. **Stała `COLORIFY_ADMIN_CUSTOM_SCHEME_KEY`** (`colorify-custom`) — powiązana z JS i radio w profilu.
3. **Duplikaty kluczy w tablicach PHP** (np. dwie grupy `'warm'`) — nadpisują się i gubią schematy w UI.
4. **Kolejność hooków** `admin_init` priority 99 dla rejestracji schematów.
5. **Wersje assetów** — po zmianie CSS/JS zwiększ stałe w bootstrapie (cache bust).

### Bezpieczne zmiany

- Nowe schematy (unikalny klucz + wpis w `colorify_admin_scheme_definitions()`).
- Style wizualne w `colorify-admin-overrides.css` / `colorify-branding.css`.
- Teksty UI, etykiety, README.
- Panel ustawień wtyczki (`class-colorify-settings.php`).

---

## Co zrobiliśmy (historia funkcji)

| Funkcja | Implementacja |
|---------|---------------|
| Dark / Light mode | Klasa `colorify-admin-light`, switch floating, meta `colorify_admin_appearance` |
| ~40 schematów | `colorify-admin-schemes.php` + `colorify-admin-colors.css` |
| Własna paleta 4 kolory | Karta w profilu, live preview w JS |
| Dostrojenie HSL | Modal z zakresem −90…+90, pola number + range |
| Responsywność | Mobile-first grid 1→2→3→4 kolumny schematów |
| Przycisk **Zapisz** | `.colorify-tuning-card__actions` — prawy dolny róg karty dostrojenia |
| Zakres globalny | Ustawienia → Colorify, przełącznik per-user / global |
| Pakiet wtyczki | Ten katalog — gotowy do aktywacji po wyłączeniu MU-pluginu |

---

## Wersjonowanie assetów (cache bust)

W `colorify-by-inyfinn.php` / MU-plugin:

```php
const COLORIFY_BRANDING_CSS_VERSION  = '1.11.3';
const COLORIFY_APPEARANCE_JS_VERSION = '1.8.0';
const COLORIFY_ADMIN_OVERRIDES_VER   = '1.7.0';
```

**Po każdej zmianie CSS/JS — podbij odpowiednią stałą.**

---

## Dla agentów AI / developerów

1. Zacznij od **`/wordpress`** → `wp-plugin-development`.
2. Przeczytaj ten README i `colorify-admin-schemes.php` (definicje schematów).
3. Zmiany UI → `colorify-admin-overrides.css` + `colorify-admin-appearance.js`.
4. Zmiany kolorów/tokenów → PHP schemes + test dark **i** light.
5. Nie usuwaj prefiksów funkcji `colorify_*` bez migracji — używane w całym stacku.
6. Testuj na **Profil użytkownika** (`/wp-admin/profile.php`) i po hard refresh (`Ctrl+Shift+R`).

### Znane pułapki

- Dwa klucze `'warm'` w `colorify_admin_scheme_color_groups()` powodowały znikające schematy — każda grupa musi mieć **unikalny** klucz.
- `scheme_order_for_js` bierze się z definicji — schematy poza grupami muszą trafić do `$ordered` (fallback).
- Edycja cudzego profilu: `canSaveMode = false` — przycisk Zapisz pokazuje komunikat.

---

## Aktywacja wtyczki

1. **Wtyczki → Colorify by INYFINN → Aktywuj** (moduł MU już usunięty z tej instalacji).
2. Ustaw zakres w **Ustawienia → Colorify** (per użytkownik lub globalne).
3. Zweryfikuj profil użytkownika, listę wtyczek i przełącznik dark/light.
4. Po zmianach CSS/JS: hard refresh `Ctrl+Shift+R`.

---

## Licencja i autor

© 2026 [INYFINN](https://inyfinn.art) · Projekt: Colorify CMS (headless)
