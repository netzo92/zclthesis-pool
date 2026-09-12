#!/usr/bin/env bash
set -euo pipefail
zcl_test_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
zcl_test_build="$(mktemp -d "${TMPDIR:-/tmp}/stratum-scheduling-test.XXXXXX")"
trap 'rm -rf "$zcl_test_build"' EXIT
# Ubuntu 24.04: build-essential, default-libmysqlclient-dev, libsodium-dev.
zcl_test_flags=(-std=c++11 -O1 -g -fsanitize=address,undefined
  -fno-omit-frame-pointer -ffunction-sections -fdata-sections
  -I/usr/include/mysql -I"$zcl_test_dir/..")
zcl_test_objects=()
for zcl_source in job job_send job_core client_core coind list object util humanize_number uint256 utilstrencodings; do
  "${CXX:-c++}" "${zcl_test_flags[@]}" -c "$zcl_test_dir/../$zcl_source.cpp" \
    -o "$zcl_test_build/$zcl_source.o"
  zcl_test_objects+=("$zcl_test_build/$zcl_source.o")
done
"${CXX:-c++}" "${zcl_test_flags[@]}" "$zcl_test_dir/job-scheduling.cpp" \
  "${zcl_test_objects[@]}" -Wl,--gc-sections -pthread -o "$zcl_test_build/job-scheduling"
UBSAN_OPTIONS=halt_on_error=1 ASAN_OPTIONS=halt_on_error=1 "$zcl_test_build/job-scheduling"
