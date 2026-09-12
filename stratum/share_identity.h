#ifndef STRATUM_SHARE_IDENTITY_H
#define STRATUM_SHARE_IDENTITY_H

#include <cstring>
extern "C" void sha256_double_hash(const char *, char *, unsigned int);

namespace stratum_share {

// ZCL Stratum has no separate extranonce2 field. Reuse that internal cache-key
// component for the FULL SHA256d of the validated, lower-case, canonical
// 806-character solution. This does not alter the miner's nonce or block bytes.
inline void zcl_solution_key(const char *canonical_solution, char (&output)[65])
{
    unsigned char digest[32];
    sha256_double_hash(canonical_solution, reinterpret_cast<char *>(digest), 806);
    const char *digits = "0123456789abcdef";
    for (unsigned int i = 0; i < 32; ++i) {
        output[2 * i] = digits[digest[i] >> 4];
        output[2 * i + 1] = digits[digest[i] & 15];
    }
    output[64] = '\0';
}

template <typename Share>
inline bool matches(const Share &share, int jobid, const char *extranonce2,
    const char *ntime, const char *nonce, const char *nonce1)
{
    return share.jobid == jobid && !std::strcmp(share.extranonce2, extranonce2) &&
        !std::strcmp(share.ntime, ntime) && !std::strcmp(share.nonce, nonce) &&
        !std::strcmp(share.nonce1, nonce1);
}

} // namespace stratum_share
#endif
