#!/usr/bin/env bash
set -euo pipefail
zcl_test_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
zcl_test_build="$(mktemp -d "${TMPDIR:-/tmp}/stratum-equihash-test.XXXXXX")"
trap 'rm -rf "$zcl_test_build"' EXIT
# Ubuntu: build-essential and libsodium-dev. Compile the production hash files,
# not a duplicate verifier implementation. No daemon, database or GPU is used.
"${CC:-cc}" -std=c11 -O1 -g -fsanitize=address,undefined \
  -fno-omit-frame-pointer -c "$zcl_test_dir/../algos/sha256.c" \
  -o "$zcl_test_build/sha256.o"
"${CXX:-c++}" -std=c++11 -O1 -g -fsanitize=address,undefined \
  -fno-omit-frame-pointer "$zcl_test_dir/verify-equihash1927.cpp" \
  "$zcl_test_dir/../algos/equihash.cpp" "$zcl_test_build/sha256.o" \
  -lsodium -o "$zcl_test_build/verify-equihash1927"
UBSAN_OPTIONS=halt_on_error=1 ASAN_OPTIONS=halt_on_error=1 \
  "$zcl_test_build/verify-equihash1927" "$zcl_test_dir/fixtures/zcl-webgpu1927.txt"
