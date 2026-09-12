#ifndef STRATUM_SUBMIT_STATUS_H
#define STRATUM_SUBMIT_STATUS_H

#include <cstring>

namespace stratum_status {

// A deleted ZCL job cannot receive share credit. Report that to the miner
// without counting normal job turnover as an abusive/invalid submission.
// Preserve the inherited behavior for other coins and algorithm variants.
template <typename ErrorReply, typename LegacyReply>
inline bool deleted_job(bool deleted, const char *symbol, int n, int k,
    ErrorReply error, LegacyReply legacy)
{
    if (!deleted) return false;
    if (symbol && !std::strcmp(symbol, "ZCL") && n == 192 && k == 7)
        error(21, "Stale job");
    else
        legacy();
    return true;
}

} // namespace stratum_status
#endif
