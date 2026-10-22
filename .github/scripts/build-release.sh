#!/usr/bin/env bash
# Đóng gói một bản phát hành LQCMS từ commit HEAD (chỉ tệp đã commit — không bao giờ lẫn storage/, config, ảnh tải lên).
#   .github/scripts/build-release.sh <phiên bản> [thư mục ra]
# Tạo 2 tệp (đính kèm vào GitHub Release cùng tag v<phiên bản>):
#   lqcms-<v>-update.zip  gói cập nhật cho site đã cài (Cài đặt › Cập nhật tải tệp này): chỉ mã lõi trong Updater::PATHS —
#                         KHÔNG có database/ (site đã cài nâng cấp DB bằng Migrations), docs/, theme/plugin.
#   lqcms-<v>.zip         gói cài mới đầy đủ (có database/, theme/plugin mẫu) — KHÔNG có docs/ (tài liệu chỉ để trên GitHub).
set -euo pipefail
VERSION="${1:?Cần phiên bản, vd. 0.2.0}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="${2:-$ROOT/dist}"
cd "$ROOT"

[[ -z "$(git status --porcelain)" ]] || { echo "Còn thay đổi chưa commit — commit trước rồi mới đóng gói." >&2; exit 1; }
CODE_VERSION="$(sed -n "s/^define('LQ_VERSION', '\(.*\)');/\1/p" app/bootstrap.php)"
[[ "$CODE_VERSION" == "$VERSION" ]] || { echo "LQ_VERSION trong app/bootstrap.php là $CODE_VERSION, khác $VERSION." >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
git archive --format=tar --prefix=lqcms/ HEAD | tar -x -C "$TMP"
SRC="$TMP/lqcms"
rm -rf "$SRC/docs" "$SRC/.github" "$SRC/.gitignore"

# Gói cập nhật: đúng danh sách Updater::PATHS.
UPD="$TMP/update/lqcms"; mkdir -p "$UPD/content"
for p in app assets index.php .htaccess nginx.conf.example README.md; do cp -R "$SRC/$p" "$UPD/$p"; done
cp "$SRC/content/.htaccess" "$UPD/content/.htaccess"

mkdir -p "$OUT"; OUT="$(cd "$OUT" && pwd)"
rm -f "$OUT/lqcms-$VERSION-update.zip" "$OUT/lqcms-$VERSION.zip"
(cd "$TMP/update" && zip -qrX "$OUT/lqcms-$VERSION-update.zip" lqcms)
(cd "$TMP" && zip -qrX "$OUT/lqcms-$VERSION.zip" lqcms)

# Tự kiểm tra: không lọt docs/ ở đâu, gói cập nhật không có database/.
if unzip -Z1 "$OUT/lqcms-$VERSION-update.zip" | grep -qE '^lqcms/(database|docs|content/(themes|plugins|uploads))(/|$)'; then echo "Gói cập nhật chứa thư mục không được phép!" >&2; exit 1; fi
if unzip -Z1 "$OUT/lqcms-$VERSION.zip" | grep -qE '^lqcms/(docs|\.github|storage)(/|$)'; then echo "Gói cài mới chứa thư mục không được phép!" >&2; exit 1; fi
ls -la "$OUT"/lqcms-"$VERSION"*.zip
