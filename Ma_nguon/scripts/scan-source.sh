#!/usr/bin/env bash
set -uo pipefail

# Kiểm tra tham số đầu vào
if [[ $# -ne 1 ]]; then
    echo 'Usage: scan-source.sh DIRECTORY' >&2
    exit 2
fi

root="$1"

# TODO 1: Kiểm tra thư mục và PHP
if [[ ! -d "$root" || ! -r "$root" || ! -x "$root" ]]; then
    echo "ERROR: thư mục không tồn tại hoặc không thể đọc: $root" >&2
    exit 2
fi

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: không tìm thấy PHP" >&2
    exit 2
fi

# Tạo danh sách file PHP
list="$(mktemp)" || exit 2
trap 'rm -f -- "$list"' EXIT

if ! find "$root" -type f -name '*.php' -print0 > "$list"; then
    echo "ERROR: không thể quét thư mục" >&2
    exit 2
fi

total=0
passed=0
failed=0

# TODO 2: Kiểm tra cú pháp tất cả file PHP
while IFS= read -r -d '' file; do
    total=$((total + 1))

    if php -l "$file" >/dev/null 2>&1; then
        passed=$((passed + 1))
        printf 'PASS %s\n' "$file"
    else
        failed=$((failed + 1))
        printf 'FAIL %s\n' "$file"
    fi
done < "$list"

printf 'TOTAL=%s PASS=%s FAIL=%s\n' "$total" "$passed" "$failed"

# TODO 3: Trả về mã thoát
if [[ "$total" -eq 0 ]]; then
    echo "ERROR: không tìm thấy file PHP" >&2
    exit 2
elif [[ "$failed" -gt 0 ]]; then
    exit 1
else
    exit 0
fi
