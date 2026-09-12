#include "../stratum.h"
#include <cassert>
#include <memory>

// Exercise production selection, assignment, broadcast, eligibility, list,
// object lifetime, and job history code. Only external I/O is replaced.
CommonList g_list_job;
CommonList g_list_client;
bool g_autoexchange = false;
bool g_debuglog_list = false;
bool g_stratum_reconnect = false;
bool is_kawpow = false, is_firopow = false, is_phihash = false, is_meowpow = false;
char g_stratum_algo[256] = "equihash192";
char g_tcp_server[1024] = {};
int g_tcp_port = 0;
uint32_t g_equihash_wn = 192, g_equihash_wk = 7;
time_t g_last_broadcasted = 0;
YAAMP_ALGO *g_current_algo = nullptr;
YAAMP_DB *g_db = nullptr;
std::vector<std::string> sent;
YAAMP_JOB *expected_retained = nullptr;
void job_assign_clients(YAAMP_JOB *, double);

int socket_send_raw(YAAMP_SOCKET *, const char *data, int size)
{
    if (expected_retained) assert(expected_retained->lock_count > 0);
    sent.emplace_back(data, size);
    return size;
}
int socket_send(YAAMP_SOCKET *, const char *, ...) { assert(false); return -1; }
void kawpow_job_mining_notify_buffer(YAAMP_JOB *, YAAMP_CLIENT *, char *) { assert(false); }
void db_clear_worker(YAAMP_DB *, YAAMP_CLIENT *) { assert(false); }
void client_adjust_difficulty(YAAMP_CLIENT *) {}
bool rpc_connected(YAAMP_RPC *) { return true; }
bool remote_can_mine(YAAMP_REMOTE *) { return false; }

static std::unique_ptr<YAAMP_JOB> make_job(int id, YAAMP_COIND *coin, YAAMP_JOB_TEMPLATE *templ)
{
    std::unique_ptr<YAAMP_JOB> job(new YAAMP_JOB());
    job->id = id;
    job->coind = coin;
    job->templ = templ;
    job->status = JOB_STATUS_ACTIVE;
    return job;
}

static void check_initial(YAAMP_CLIENT& client, YAAMP_JOB *expected)
{
    sent.clear();
    client.jobid_sent = 0;
    expected_retained = expected;
    job_send_last(&client);
    expected_retained = nullptr;
    assert(client.jobid_sent == (expected ? expected->id : 0));
    assert(sent.size() == (expected ? 1u : 0u));
    if (expected) {
        char prefix[96];
        std::snprintf(prefix, sizeof(prefix), "\"method\":\"mining.notify\",\"params\":[\"%x\",", expected->id);
        assert(sent[0].find(prefix) != std::string::npos);
        assert(expected->lock_count == 0);
    }
}

static void check_assignment(YAAMP_JOB& job, YAAMP_CLIENT& client, bool expected, double maxhash)
{
    client.jobid_next = 0;
    job_assign_clients(&job, maxhash);
    assert(client.jobid_next == (expected ? job.id : 0));
}

int main()
{
    YAAMP_ALGO algo = {};
    std::strcpy(algo.name, g_stratum_algo);
    g_current_algo = &algo;
    std::unique_ptr<YAAMP_COIND> coin(new YAAMP_COIND());
    coin->id = 1;
    coin->enable = coin->auto_ready = true;
    coin->height = 100;
    coin->difficulty = 1;
    coin->auto_exchange = false;
    std::strcpy(coin->symbol, "ZCL");
    std::strcpy(coin->symbol2, "ZCLASSIC");
    std::unique_ptr<YAAMP_JOB_TEMPLATE> templ(new YAAMP_JOB_TEMPLATE());
    std::strcpy(templ->version, "20000000");
    std::strcpy(templ->prevhash_hex, std::string(64, '1').c_str());
    std::strcpy(templ->merkleroot, std::string(64, '2').c_str());
    std::strcpy(templ->saplingroothash, std::string(64, '3').c_str());
    std::strcpy(templ->ntime, "65000000");
    // Actual mainnet block 3192878 compact target; see zcl-webgpu1927.txt.
    std::strcpy(templ->nbits, "1e151c4a");
    templ->height = 101;
    auto retired = make_job(1, coin.get(), templ.get());
    retired->deleted = true;
    auto older = make_job(2, coin.get(), templ.get());
    auto latest = make_job(3, coin.get(), templ.get());
    auto waiting = make_job(4, coin.get(), templ.get());
    waiting->status = JOB_STATUS_WAITING;
    // An inactive head reproduces the first/prev traversal bug; a reordered
    // tail establishes that simply walking backward is not sufficient.
    g_list_job.AddTail(retired.get());
    g_list_job.AddTail(latest.get());
    g_list_job.AddTail(older.get());
    g_list_job.AddTail(waiting.get());
    YAAMP_SOCKET socket = {};
    socket.sock = -1; // The broadcast setsockopt fails harmlessly; no socket exists.
    std::unique_ptr<YAAMP_CLIENT> client(new YAAMP_CLIENT());
    client->coinid = coin->id;
    client->sock = &socket;
    client->speed = 1;
    std::strcpy(client->password, "c=ZCL");
    std::strcpy(client->extranonce1_default, "00000001");
    std::strcpy(client->extranonce1_last, "00000001");
    client->extranonce2size_default = client->extranonce2size_last = 28;
    g_list_client.AddTail(client.get());

    check_initial(*client, latest.get());
    check_assignment(*latest, *client, true, 0);
    // A real later job goes through the same production reset/assign/broadcast
    // path used by the pool, with both exchange switches still disabled.
    latest->deleted = true;
    auto next = make_job(5, coin.get(), templ.get());
    g_list_job.AddTail(next.get());
    job_reset_clients();
    job_assign_clients(next.get(), 0);
    sent.clear();
    job_broadcast(next.get());
    assert(client->jobid_next == next->id && client->jobid_sent == next->id);
    assert(client->job_history[0] == next->id);
    assert(sent.size() == 1 && sent[0].find("\"params\":[\"5\",") != std::string::npos);
    job_broadcast(next.get());
    assert(sent.size() == 1); // No duplicate notification of the same job.

    for (double maxhash : {0.0, -1.0}) {
        client->coinid = 2;
        check_initial(*client, nullptr);
        check_assignment(*next, *client, false, maxhash);
        client->coinid = 0;
        check_initial(*client, nullptr);
        check_assignment(*next, *client, false, maxhash);
        client->coinid = coin->id;
        client->coins_mining_list = {"OTHER"};
        check_initial(*client, nullptr);
        check_assignment(*next, *client, false, maxhash);
        client->coins_mining_list = {"ZCL"};
        check_initial(*client, next.get());
        check_assignment(*next, *client, true, maxhash);
        client->coins_ignore_list = {"ZCL"};
        check_initial(*client, nullptr);
        check_assignment(*next, *client, false, maxhash);
        client->coins_mining_list = {"ZCLASSIC"};
        client->coins_ignore_list.clear();
        check_initial(*client, next.get());
        check_assignment(*next, *client, true, maxhash);
        client->coins_ignore_list = {"ZCLASSIC"};
        check_initial(*client, nullptr);
        check_assignment(*next, *client, false, maxhash);
        client->coins_mining_list.clear();
        client->coins_ignore_list.clear();
    }
    // The direct-ZCL exception must not enable exchange or change another
    // coin's requirement for an explicit mining selection.
    g_autoexchange = true;
    check_assignment(*next, *client, false, 0);
    client->coins_mining_list = {"ZCL"};
    check_assignment(*next, *client, true, 0);
    g_autoexchange = false;
    client->coins_mining_list.clear();
    std::strcpy(coin->symbol, "OTHER");
    check_assignment(*next, *client, false, 0);
    client->coins_mining_list = {"OTHER"};
    check_assignment(*next, *client, true, 0);
    assert(!g_autoexchange && !coin->auto_exchange);
    while (g_list_job.first) g_list_job.Delete(g_list_job.first);
    while (g_list_client.first) g_list_client.Delete(g_list_client.first);
    std::cout << "Production ZCL scheduling passed: newest eligible initial job, subsequent broadcast, "
        "no duplicate notify, retained object, payout coin/mc/nc gates and non-ZCL exchange behavior. No mining.\n";
}
