#include "../stratum.h"
#include <cassert>
#include <memory>

// Link the actual production submit builder and hash/string helpers. This
// driver never connects to a pool, searches for a proof, or submits a share.
YAAMP_ALGO *g_current_algo = nullptr;
void build_submit_values_equihash(YAAMP_JOB_VALUES *, YAAMP_JOB_TEMPLATE *,
    const char *, const char *, const char *, const char *);

int main()
{
    YAAMP_ALGO algo = {};
    algo.hash_function = sha256_double_hash;
    g_current_algo = &algo;
    std::unique_ptr<YAAMP_JOB_TEMPLATE> templ(new YAAMP_JOB_TEMPLATE());
    std::strcpy(templ->version, "20000000");
    std::strcpy(templ->prevhash_hex, "000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f");
    std::strcpy(templ->saplingroothash, "202122232425262728292a2b2c2d2e2f303132333435363738393a3b3c3d3e3f");
    std::strcpy(templ->nbits, "1e07ffff");
    std::strcpy(templ->coinbase, "00");
    const std::string nonce = "0405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f";
    const std::string solution = "fd9001" + std::string(800, '0');
    const std::string expected =
        "00000020"
        "1f1e1d1c1b1a191817161514131211100f0e0d0c0b0a09080706050403020100"
        // Independently calculated with Python hashlib: SHA256d(bytes.fromhex('00')).
        "1406e05881e299367766d313e26c05564ec91bf721d31726bd6e46e60689539a"
        "3f3e3d3c3b3a393837363534333231302f2e2d2c2b2a29282726252423222120"
        "44332211ffff071e00010203" + nonce;
    assert(expected.size() == 280);
    for (unsigned int iteration = 0; iteration < 4; ++iteration) {
        std::unique_ptr<YAAMP_JOB_VALUES> result(new YAAMP_JOB_VALUES());
        // The build script poisons every uninitialized automatic variable in
        // the production source. This reproduces the formerly unterminated
        // reversed-field buffers regardless of incidental zero stack pages.
        build_submit_values_equihash(result.get(), templ.get(), "00010203",
            "11223344", nonce.c_str(), solution.c_str());
        assert(std::string(result->header) == expected);
        assert(std::strlen(result->header_be) == 543 * 2);
        assert(std::string(result->header_be) == expected + solution);
        unsigned char expected_bytes[543] = {};
        binlify(expected_bytes, (expected + solution).c_str());
        assert(!std::memcmp(result->header_bin, expected_bytes, 140));
        assert(!std::memcmp(result->solution_bin, expected_bytes + 143, 400));
        unsigned char expected_hash[32];
        sha256_double_hash(reinterpret_cast<const char *>(expected_bytes),
            reinterpret_cast<char *>(expected_hash), sizeof(expected_bytes));
        assert(!std::memcmp(result->hash_bin, expected_hash, sizeof(expected_hash)));
    }
    std::cout << "Production Equihash submit header is exactly 140 bytes and hashes "
        "the canonical 543-byte serialization with poisoned automatic storage. No mining.\n";
}
