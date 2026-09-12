#!/usr/bin/env bash
set -euo pipefail
zcl_test_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
zcl_test_build="$(mktemp -d "${TMPDIR:-/tmp}/stratum-submit-test.XXXXXX")"
trap 'rm -rf "$zcl_test_build"' EXIT
# The vendored parser predates current compiler warnings. Keep sanitizer
# instrumentation on it, and use strict warnings for our validator/tests.
"${CXX:-c++}" -std=c++11 -w -g \
  -fsanitize=address,undefined -fno-omit-frame-pointer \
  -c "$zcl_test_dir/../json.cpp" -o "$zcl_test_build/json.o"
"${CXX:-c++}" -std=c++11 -Wall -Wextra -Werror -g \
  -fsanitize=address,undefined -fno-omit-frame-pointer \
  "$zcl_test_dir/submit_validation_test.cpp" "$zcl_test_build/json.o" \
  -o "$zcl_test_build/submit-test"
UBSAN_OPTIONS=halt_on_error=1 ASAN_OPTIONS=halt_on_error=1 "$zcl_test_build/submit-test"
