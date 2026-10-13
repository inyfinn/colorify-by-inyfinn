# Atrapa GitHub Releases (tylko narzędzie testowe)

Ten katalog służy do prób aktualizacji Colorify bez publikowania wydania. **Nie trafia do paczki wtyczki**
(`tools/` jest wykluczony z ZIP-a) i nie jest potrzebny do działania wtyczki.

- `server.mjs`: serwer Node udający `api.github.com/repos/<repo>/releases/latest` i adresy
  `github.com/<repo>/releases/download/...`. Stan (tag, tryb błędu, suma `digest`, podmiana pliku) zmienia się przez
  `POST /__config`. Uruchomienie: `node tools/mock-release/server.mjs --port 8765 --zip 2.0.0=<plik.zip>`.
- `lab-mock.php`: mu-plugin do kopii testowej WordPressa (Docker), przekierowuje zapytania o GitHuba na atrapę
  (`host.docker.internal:8765`).
- `lab-old-check.php`: mu-plugin pokazujący link starego sprawdzania aktualizacji wersji 1.x.
- `lab-chroot-fs.php`, `lab-chroot-fs-class.php`: mu-plugin udający system plików FTP (ścieżki zdalne różne od
  lokalnych), do sprawdzania instalacji paczki przy `FS_METHOD` innym niż `direct`.

Nie wgrywaj tych plików na stronę produkcyjną. Nie zawierają haseł ani prawdziwych adresów poza `github.com` (do którego atrapa się podszywa) i zastrzeżoną domeną
`example.com` w trybie błędu `foreign`.
