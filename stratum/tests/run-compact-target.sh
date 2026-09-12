#!/usr/bin/env bash
set -euo pipefail
zcl_test_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
zcl_test_build="$(mktemp -d "${TMPDIR:-/tmp}/stratum-target-test.XXXXXX")"
trap 'rm -rf "$zcl_test_build"' EXIT
# Ubuntu 24.04: build-essential, default-libmysqlclient-dev, libsodium-dev, python3.
zcl_test_flags=(-std=c++11 -O1 -g -fsanitize=address,undefined
  -fno-omit-frame-pointer -ffunction-sections -fdata-sections
  -I/usr/include/mysql -I"$zcl_test_dir/..")
zcl_test_objects=()
for zcl_source in util list object uint256 arith_uint256 utilstrencodings; do
  "${CXX:-c++}" "${zcl_test_flags[@]}" -c "$zcl_test_dir/../$zcl_source.cpp" \
    -o "$zcl_test_build/$zcl_source.o"
  zcl_test_objects+=("$zcl_test_build/$zcl_source.o")
done
"${CC:-cc}" -std=c11 -O1 -g -fsanitize=address,undefined \
  -fno-omit-frame-pointer -c "$zcl_test_dir/../algos/sha256.c" -o "$zcl_test_build/sha256.o"
"${CXX:-c++}" "${zcl_test_flags[@]}" "$zcl_test_dir/compact-target.cpp" \
  "$zcl_test_dir/../algos/equihash.cpp" "$zcl_test_build/sha256.o" \
  "${zcl_test_objects[@]}" -Wl,--gc-sections -lsodium -pthread -o "$zcl_test_build/compact-target"
python3 "$zcl_test_dir/compact-target-fixtures.py" "$zcl_test_build/targets.txt"
UBSAN_OPTIONS=halt_on_error=1 ASAN_OPTIONS=halt_on_error=1 \
  "$zcl_test_build/compact-target" "$zcl_test_build/targets.txt" "$zcl_test_dir/fixtures/zcl-webgpu1927.txt"
