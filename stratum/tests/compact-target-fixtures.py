#!/usr/bin/env python3
"""Independent unlimited-precision target oracle; no hashing search or networking."""
import sys

MASK64 = (1 << 64) - 1
MASK256 = (1 << 256) - 1

def target(bits):
    if len(bits) != 8 or any(c not in "0123456789abcdefABCDEF" for c in bits):
        return None
    compact = int(bits, 16)
    exponent, coefficient = compact >> 24, compact & 0x7fffff
    value = coefficient << (8 * (exponent - 3)) if exponent >= 3 else coefficient >> (8 * (3 - exponent))
    return value if not compact & 0x800000 and 0 < value <= MASK256 else None

bits_cases = ["1e151c4a", "1e07ffff", "1d00ffff", "01010000", "02008000",
              "03000001", "1807ffff", "1907ffff", "1a07ffff", "1b07ffff",
              "1c07ffff", "2000ffff", "2100ffff", "220000ff", "22000100",
              "23000001", "ff123456", "1e951c4a", "00000000", "01000001",
              "1e000000", "1e151c4g", "1e151c4", "01e151c4a", "-e151c4a", "1E151C4A"]
with open(sys.argv[1], "w", encoding="ascii") as output:
    for bits in bits_cases:
        value = target(bits)
        hashes = [0, MASK256] if value is None else sorted({0, value - 1, value, min(value + 1, MASK256), MASK256})
        for hash_value in hashes:
            accepted = int(value is not None and hash_value <= value)
            high64 = (value >> 192) & MASK64 if value is not None else 0
            standard64 = (value >> 176) & MASK64 if value is not None else 0
            output.write(f"{bits} {hash_value.to_bytes(32, 'little').hex()} {accepted} {high64:016x} {standard64:016x}\n")
