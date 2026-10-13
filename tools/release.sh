#!/usr/bin/env bash
# Publikuje wydanie Colorify na GitHubie: buduje ZIP, tworzy release v<wersja> z assetem
# colorify-by-inyfinn.zip i trescia zawierajaca linie "Requires at least" i "Requires PHP"
# (czyta je updater wtyczki, zeby WordPress nie oferowal wersji niezgodnej ze strona).
#
#   tools/release.sh <wersja> [owner/repo] [--dry-run] [--prerelease]
#
# Wymaga: zatwierdzonych i WYPCHNIETYCH zmian (tag powstaje na biezacym commicie, ktory musi istniec
# na zdalnym repo), tagu, ktorego jeszcze nie ma ani lokalnie, ani zdalnie, oraz gh zalogowanego.
# Skrypt niczego nie wypycha: galaz wypychasz sam.
# --dry-run:     buduje paczke i wypisuje polecenie gh oraz tresc release, niczego nie publikuje;
#                kontrole zdalne (commit, tag) tylko ostrzegaja.
# --prerelease:  wydanie oznaczone jako pre-release (endpoint releases/latest, z ktorego korzysta updater,
#                go NIE zwraca, wiec strony go nie dostana). Bez flagi: zwykle wydanie "Latest".
set -euo pipefail

dry=0
pre=0
pos=()
for a in "$@"; do
	case "$a" in
		--dry-run) dry=1 ;;
		--prerelease) pre=1 ;;
		--*) echo "nieznana opcja: $a" >&2; exit 2 ;;
		*) pos+=("$a") ;;
	esac
done

version="${pos[0]:-}"
repo="${pos[1]:-inyfinn/colorify-by-inyfinn}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.]+)?$ ]]; then
	echo "uzycie: tools/release.sh <wersja, np. 2.0.0 albo 2.0.0-rc1> [owner/repo] [--dry-run] [--prerelease]" >&2
	exit 2
fi
if [[ ! "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
	echo "niepoprawne repozytorium: $repo" >&2
	exit 2
fi

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
tag="v$version"
sha="$(git rev-parse HEAD)"

# Wymagania wydania z naglowka wtyczki (te same wartosci widzi WordPress po instalacji).
req_wp="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Requires at least:[[:space:]]*\([0-9.]*\).*/\1/p' colorify-by-inyfinn.php | head -n1)"
req_php="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Requires PHP:[[:space:]]*\([0-9.]*\).*/\1/p' colorify-by-inyfinn.php | head -n1)"
if [[ -z "$req_wp" || -z "$req_php" ]]; then
	echo "brak Requires at least / Requires PHP w naglowku colorify-by-inyfinn.php" >&2
	exit 1
fi

# Wpis z CHANGELOG.md dla tej wersji (sekcja "## <wersja>" do nastepnego "## ").
# Wersja z sufiksem (2.0.0-rc1) bez wlasnej sekcji bierze sekcje wersji bazowej (2.0.0).
changelog_section() { awk -v v="$1" '$0 == "## " v {on=1; next} /^## /{on=0} on' CHANGELOG.md 2>/dev/null || true; }
changes="$(changelog_section "$version")"
if [[ -z "${changes//[[:space:]]/}" && "$version" == *-* ]]; then
	changes="$(changelog_section "${version%%-*}")"
fi

notes="$(mktemp)"
trap 'rm -f "$notes"' EXIT
{
	echo "Requires at least: $req_wp"
	echo "Requires PHP: $req_php"
	echo
	if [[ -n "${changes//[[:space:]]/}" ]]; then
		echo "$changes"
	else
		echo "Colorify by INYFINN $version"
	fi
} > "$notes"

# Kontrole: blad zatrzymuje skrypt, a przy --dry-run tylko ostrzega.
problem() {
	if [[ $dry -eq 1 ]]; then
		echo "[dry-run] OSTRZEZENIE: $1" >&2
	else
		echo "$1" >&2
		exit 1
	fi
}

if [[ -n "$(git status --porcelain -- . ':(exclude)dist')" ]]; then
	problem "drzewo robocze ma niezatwierdzone zmiany: zatwierdz je przed wydaniem"
fi
if git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
	problem "tag $tag juz istnieje lokalnie"
fi
if ! command -v gh >/dev/null 2>&1; then
	problem "brak polecenia gh"
else
	# Commit docelowy musi byc na zdalnym repo (gh release create --target wymaga istniejacego commita).
	if ! gh api "repos/$repo/commits/$sha" --silent >/dev/null 2>&1; then
		problem "commit $sha nie istnieje w $repo (repo nie istnieje albo galaz nie zostala wypchnieta: git push)"
	fi
	# Tag nie moze istniec zdalnie (gh release create stworzyloby wtedy wydanie na cudzym commicie).
	if gh api "repos/$repo/git/ref/tags/$tag" --silent >/dev/null 2>&1; then
		problem "tag $tag juz istnieje w $repo"
	fi
fi

node tools/build-zip.mjs "$version"

cmd=(gh release create "$tag" "dist/colorify-by-inyfinn.zip"
	--repo "$repo" --title "$tag" --notes-file "$notes"
	--target "$sha")
if [[ $pre -eq 1 ]]; then
	cmd+=(--prerelease)
else
	cmd+=(--latest)
fi

if [[ $dry -eq 1 ]]; then
	echo
	echo "[dry-run] ${cmd[*]}"
	echo "[dry-run] tresc release:"
	sed 's/^/    /' "$notes"
	exit 0
fi

"${cmd[@]}"
echo "Opublikowano $tag w $repo"
