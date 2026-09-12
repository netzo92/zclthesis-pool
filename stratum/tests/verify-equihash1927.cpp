#include <cassert>
#include <fstream>
#include <iostream>
#include <string>
#include <vector>
#include "../share_identity.h"

// Use the exact production verifier; this driver never searches for a proof.
bool verifyEH(const char *, const char *, int, int, char *);

static int digit(char c)
{
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    return -1;
}

int main(int argc, char **argv)
{
    assert(argc == 2);
    std::ifstream fixtures(argv[1]);
    assert(fixtures.good());
    std::string line;
    unsigned int checked = 0;
    struct CachedShare { int jobid; char extranonce2[65]; const char *ntime; const char *nonce; const char *nonce1; };
    std::vector<CachedShare> seen;
    char personalization[] = "ZcashPoW";
    while (std::getline(fixtures, line)) {
        if (line.empty() || line[0] == '#') continue;
        assert(line.size() == 543 * 2);
        std::vector<char> bytes(543);
        for (size_t i = 0; i < bytes.size(); ++i) {
            const int hi = digit(line[2 * i]), lo = digit(line[2 * i + 1]);
            assert(hi >= 0 && lo >= 0);
            bytes[i] = static_cast<char>((hi << 4) | lo);
        }
        assert(static_cast<unsigned char>(bytes[140]) == 253);
        assert(static_cast<unsigned char>(bytes[141]) == 144);
        assert(bytes[142] == 1);
        assert(verifyEH(bytes.data(), bytes.data() + 143, 192, 7, personalization));
        CachedShare share = {42, {}, "01020304", "same-header-nonce", "same-connection"};
        stratum_share::zcl_solution_key(line.c_str() + 280, share.extranonce2);
        assert(std::strlen(share.extranonce2) == 64);
        for (const auto &prior : seen)
            assert(!stratum_share::matches(prior, share.jobid, share.extranonce2,
                share.ntime, share.nonce, share.nonce1));
        seen.push_back(share);
        // The exact same proof remains a duplicate even after other proofs
        // for this header have been recorded. Use production's match predicate.
        for (const auto &prior : seen) {
            bool replay_found = false;
            for (const auto &stored : seen)
                replay_found |= stratum_share::matches(stored, prior.jobid,
                    prior.extranonce2, prior.ntime, prior.nonce, prior.nonce1);
            assert(replay_found);
        }
        bytes.back() ^= 1;
        assert(!verifyEH(bytes.data(), bytes.data() + 143, 192, 7, personalization));
        bytes.back() ^= 1;
        bytes[139] ^= 1;
        assert(!verifyEH(bytes.data(), bytes.data() + 143, 192, 7, personalization));
        bytes[139] ^= 1;
        char wrong_personalization[] = "OtherPoW";
        assert(!verifyEH(bytes.data(), bytes.data() + 143, 192, 7, wrong_personalization));
        ++checked;
    }
    assert(checked == 3);
    std::cout << "Production Equihash192,7 verifier accepted all 3 historical WebGPU proofs; "
        "rejected solution/nonce/personalization mutations. Distinct same-nonce "
        "solutions have separate share identities; exact replays match. No mining or network.\n";
}
