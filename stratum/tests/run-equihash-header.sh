#!/usr/bin/env bash
set -euo pipefail
zcl_test_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
zcl_test_build="$(mktemp -d "${TMPDIR:-/tmp}/stratum-header-test.XXXXXX")"
trap 'rm -rf "$zcl_test_build"' EXIT
# Ubuntu 24.04: build-essential, default-libmysqlclient-dev, libsodium-dev.
# Function sections let this isolated driver link the real production builder
# without any runtime socket, daemon or SQL implementation.
zcl_test_flags=(-std=c++11 -O1 -g -ftrivial-auto-var-init=pattern
  -fsanitize=address,undefined -fno-omit-frame-pointer
  -ffunction-sections -fdata-sections -I/usr/include/mysql -I"$zcl_test_dir/..")
for zcl_source in client_submit util merkle uint256 utilstrencodings; do
  "${CXX:-c++}" "${zcl_test_flags[@]}" -c "$zcl_test_dir/../$zcl_source.cpp" \
    -o "$zcl_test_build/$zcl_source.o"
done
"${CC:-cc}" -std=c11 -O1 -g -fsanitize=address,undefined \
  -fno-omit-frame-pointer -c "$zcl_test_dir/../algos/sha256.c" \
  -o "$zcl_test_build/sha256.o"
"${CXX:-c++}" "${zcl_test_flags[@]}" "$zcl_test_dir/build-equihash-header.cpp" \
  "$zcl_test_build/client_submit.o" "$zcl_test_build/util.o" \
  "$zcl_test_build/merkle.o" "$zcl_test_build/sha256.o" \
  "$zcl_test_build/uint256.o" "$zcl_test_build/utilstrencodings.o" \
  -Wl,--gc-sections -o "$zcl_test_build/build-equihash-header"
UBSAN_OPTIONS=halt_on_error=1 ASAN_OPTIONS=halt_on_error=1 \
  "$zcl_test_build/build-equihash-header"
