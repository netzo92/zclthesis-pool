#ifndef ZCL_JOB_SELECTION_H
#define ZCL_JOB_SELECTION_H

// Included after stratum.h. A direct ZCL pool pays the coin being mined;
// disabling exchange is not an opt-out from ordinary c=ZCL work assignment.
inline bool job_is_direct_zcl(const YAAMP_JOB *job)
{
    return !g_autoexchange && job->coind && !strcmp(job->coind->symbol, "ZCL");
}

inline bool job_client_allows_direct_zcl(const YAAMP_JOB *job, const YAAMP_CLIENT *client)
{
    const YAAMP_COIND *coin = job->coind;
    if (client->coinid <= 0 || client->coinid != coin->id) return false;
    const auto matches = [coin](const std::vector<std::string>& symbols) {
        return std::find(symbols.begin(), symbols.end(), coin->symbol) != symbols.end() ||
            (coin->symbol2[0] && std::find(symbols.begin(), symbols.end(), coin->symbol2) != symbols.end());
    };
    if (matches(client->coins_ignore_list)) return false;
    return client->coins_mining_list.empty() || matches(client->coins_mining_list);
}

#endif
