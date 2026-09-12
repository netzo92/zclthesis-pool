// Include the actual production translation unit so this driver can exercise
// its private block-dispatch function. Function sections discard unrelated
// network/authorization code; every reachable external side effect is stubbed.
#include "../client_submit.cpp"
#include <cassert>
#include <fstream>
#include <memory>
#include <sstream>

YAAMP_ALGO *g_current_algo = nullptr;
char g_stratum_algo[256] = "equihash192";
bool g_debuglog_hash = false;
bool g_debuglog_list = false;
unsigned int rpc_attempts = 0;
std::string candidate;
bool coind_submit(YAAMP_COIND *, const char *block)
{
    ++rpc_attempts;
    candidate = block;
    return false; // Intercept; never send an RPC or mark a live block accepted.
}
bool coind_submitgetauxblock(YAAMP_COIND *, const char *, const char *) { assert(false); return false; }
vector<string> coind_aux_merkle_branch(YAAMP_COIND_AUX **, int, int) { assert(false); return {}; }
void block_add(int, int, int, int, double, double, const char *, const char *, int, bool) { assert(false); }
bool block_confirm(int, const char *) { assert(false); return false; }

int main(int argc, char **argv)
{
    assert(argc == 3);
    YAAMP_ALGO algo = {};
    std::strcpy(algo.name, g_stratum_algo);
    algo.hash_function = sha256_double_hash;
    g_current_algo = &algo;
    std::unique_ptr<YAAMP_COIND> coin(new YAAMP_COIND());
    std::strcpy(coin->symbol, "ZCL");
    coin->powlimit_bits = 16;
    std::unique_ptr<YAAMP_JOB_TEMPLATE> templ(new YAAMP_JOB_TEMPLATE());
    templ->height = 3192878;
    templ->txcount = 1;
    std::unique_ptr<YAAMP_JOB> job(new YAAMP_JOB());
    job->coind = coin.get();
    job->templ = templ.get();
    std::unique_ptr<YAAMP_CLIENT> client(new YAAMP_CLIENT());
    std::unique_ptr<YAAMP_JOB_VALUES> values(new YAAMP_JOB_VALUES());
    std::strcpy(values->coinbase, "00"); // Synthetic body; only header/routing is tested.
    std::ifstream historical(argv[2]);
    std::string header;
    while (std::getline(historical, header) && (header.empty() || header[0] == '#')) {}
    assert(header.size() == 543 * 2);
    std::strcpy(values->header_be, header.c_str());
    binlify(values->header_bin, header.c_str());
    assert(header.substr(280, 6) == "fd9001");
    assert(verifyEH(reinterpret_cast<char *>(values->header_bin),
        reinterpret_cast<char *>(values->header_bin + 143), 192, 7, "ZcashPoW"));
    sha256_double_hash(reinterpret_cast<char *>(values->header_bin),
        reinterpret_cast<char *>(values->hash_bin), 543);
    uint256 hash;
    std::memcpy(hash.begin(), values->hash_bin, 32);
    assert(hash.GetHex() == "00000c55116b591143fdea1cbf2568b885207fb61c3d1aeb549b3c15937b0c58");
    std::strcpy(templ->nbits, "1e151c4a");
    assert(hash_meets_compact_target(values->hash_bin, templ->nbits));
    char unused[] = "";
    client_do_submit(client.get(), job.get(), values.get(), unused, unused, unused, unused, 0);
    assert(rpc_attempts == 1 && candidate.substr(0, header.size()) == header);
    assert(!job->block_found);

    std::ifstream fixtures(argv[1]);
    std::string bits, hash_hex, projected_hex, standard_hex;
    unsigned int count = 0;
    int expected;
    while (fixtures >> bits >> hash_hex >> expected >> projected_hex >> standard_hex) {
        std::strcpy(templ->nbits, bits.c_str());
        binlify(values->hash_bin, hash_hex.c_str());
        const uint64_t projected = decode_compact(bits.c_str(), 27);
        assert(projected == std::stoull(projected_hex, nullptr, 16));
        assert(decode_compact(bits.c_str(), 25) == std::stoull(standard_hex, nullptr, 16));
        assert(hash_meets_compact_target(values->hash_bin, bits.c_str()) == bool(expected));
        const uint64_t hash_high = get_equihash_difficulty(values->hash_bin);
        assert(client_hash_meets_block_target(job.get(), values->hash_bin, hash_high, projected, true) == bool(expected));
        const unsigned int before = rpc_attempts;
        client_do_submit(client.get(), job.get(), values.get(), unused, unused, unused, unused, 0);
        assert(rpc_attempts == before + expected); // Above/invalid target never reaches even the RPC stub.
        ++count;
    }
    assert(count > 90);
    arith_uint256 decoded;
    assert(!decode_compact_target(nullptr, decoded));
    assert(!decode_compact_target("", decoded));
    assert(!hash_meets_compact_target(nullptr, "1e151c4a"));
    assert(decode_compact("1e151c4a", -1000000) == 0);
    assert(decode_compact("1e151c4a", 1000000) == 0);
    // The exact comparison remains scoped to ZCL; other algorithm routes retain
    // their established projected comparison, without an exchange setting change.
    std::strcpy(coin->symbol, "OTHER");
    assert(client_hash_meets_block_target(job.get(), values->hash_bin, 10, 10, true));
    assert(!client_hash_meets_block_target(job.get(), values->hash_bin, 11, 10, true));
    std::cout << "Production target checks passed: " << count << " independent Python integer cases, "
        "mainnet 192,7 proof/hash and candidate dispatch, exact T-1/T/T+1 boundaries, "
        "invalid/zero/negative/overflow rejection, and no above-target RPC attempts. No mining.\n";
}
