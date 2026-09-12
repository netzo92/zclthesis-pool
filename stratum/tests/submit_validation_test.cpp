#include "../submit_validation.h"
#include "../submit_status.h"
#include <cassert>
#include <iostream>
#include <string>
#include <vector>

struct Json {
    json_value *value;
    explicit Json(const std::string &text) : value(json_parse(text.data(), text.size()))
    { assert(value); }
    ~Json() { json_value_free(value); }
};

static std::string quoted(const std::string &value) { return "\"" + value + "\""; }
static std::string array(const std::vector<std::string> &values)
{
    std::string result = "[";
    for (size_t i = 0; i < values.size(); ++i) {
        if (i) result += ",";
        result += values[i];
    }
    return result + "]";
}

static bool eq(const std::vector<std::string> &values, int n = 192, int k = 7)
{
    Json json(array(values));
    return stratum_input::equihash_submit(json.value, n, k, 28);
}

int main()
{
    // A retired ZCL job used to report success although no share was credited.
    // Exercise both reply callbacks, including unaffected live/non-ZCL work.
    for (bool deleted : {false, true}) {
        for (const char *symbol : {"ZCL", "ZEC", "OTHER", static_cast<const char *>(nullptr)}) {
            for (int n : {192, 200}) {
                int errors = 0, accepted = 0;
                bool handled = stratum_status::deleted_job(deleted, symbol, n, 7,
                    [&](int code, const char *reason) {
                        ++errors; assert(code == 21); assert(std::string(reason) == "Stale job");
                    }, [&]() { ++accepted; });
                const bool stale_zcl = deleted && symbol && std::string(symbol) == "ZCL" && n == 192;
                assert(handled == deleted);
                assert(errors == (stale_zcl ? 1 : 0));
                assert(accepted == (deleted && !stale_zcl ? 1 : 0));
            }
        }
    }

    std::vector<std::string> good = {quoted("t1worker.rig"), quoted("1234"),
        quoted("01020304"), quoted(std::string(56, 'a')),
        quoted("fd9001" + std::string(800, 'b'))};
    assert(eq(good));
    assert(!eq(good, 200, 9));
    assert(!eq(good, 193, 7));

    // The 400-byte ZCL solution must have canonical CompactSize 0xfd 0x90 0x01.
    for (const auto &prefix : {"9001", "fe90010000", "fd9101", "fd0190", "fc", ""}) {
        auto bad = good;
        bad[4] = quoted(std::string(prefix) + std::string(800, 'b'));
        assert(!eq(bad));
    }
    for (size_t length : {0u, 1u, 799u, 800u, 805u, 807u, 2799u, 2800u, 2801u, 64000u}) {
        auto bad = good;
        bad[4] = quoted(std::string(length, 'a'));
        assert(!eq(bad));
    }
    auto upper = good;
    upper[4] = quoted("FD9001" + std::string(800, 'B'));
    assert(eq(upper));
    auto bad_hex = good;
    bad_hex[4] = quoted("fd9001" + std::string(799, 'b') + "g");
    assert(!eq(bad_hex));

    // Preserve the other solver variants; changing length must never select a
    // different verifier or pass padded/truncated work to the native checker.
    struct Variant { int n; int k; size_t bytes; const char *prefix; };
    for (auto v : {Variant{48,5,36,"24"}, Variant{96,5,68,"44"},
        Variant{125,4,52,"34"}, Variant{144,5,100,"64"},
        Variant{200,9,1344,"fd4005"}}) {
        auto work = good;
        work[4] = quoted(std::string(v.prefix) + std::string(v.bytes * 2, 'a'));
        assert(eq(work, v.n, v.k));
        work[4] = quoted(std::string(v.prefix) + std::string(v.bytes * 2 + 2, 'a'));
        assert(!eq(work, v.n, v.k));
    }

    // Regressions for formerly unchecked nonce/time copies, wrong union types,
    // embedded NULs, truncated fields and integer parsing of arbitrary job IDs.
    for (size_t index = 0; index < good.size(); ++index) {
        for (const std::string &replacement : std::vector<std::string>{"null", "1", "true", "{}", "[]",
             quoted("a\\u0000b"), quoted(std::string(64000, 'a'))}) {
            auto bad = good;
            bad[index] = replacement;
            assert(!eq(bad));
        }
    }
    for (size_t index : {size_t(2), size_t(3)}) {
        const size_t expected = index == 2 ? 8 : 56;
        for (size_t length = 0; length < 100; ++length) {
            auto changed = good;
            changed[index] = quoted(std::string(length, '0'));
            assert(eq(changed) == (length == expected));
        }
    }
    auto extra = good; extra.push_back(quoted("ignored")); assert(!eq(extra));
    auto short_params = good; short_params.pop_back(); assert(!eq(short_params));
    auto job = good; job[1] = quoted("ffffffff"); assert(eq(job));
    job[1] = quoted("100000000"); assert(!eq(job));
    job[1] = quoted("hello"); assert(!eq(job));

    // Parse actual malformed wire envelopes before the legacy JSON accessors.
    for (const std::string text : {"null", "[]", "1", "\"x\"",
        "{\"id\":{},\"method\":\"mining.submit\",\"params\":[]}",
        "{\"id\":true,\"method\":\"mining.submit\",\"params\":[]}",
        "{\"method\":5,\"params\":[]}", "{\"method\":\"x\",\"params\":{}}",
        "{\"method\":\"x\\u0000y\",\"params\":[]}"}) {
        Json malformed(text); assert(!stratum_input::envelope(malformed.value));
    }
    for (const std::string id : {"null", "1", "\"request-1\""}) {
        Json request("{\"id\":" + id + ",\"method\":\"mining.submit\",\"params\":" + array(good) + "}");
        assert(stratum_input::envelope(request.value));
        assert(stratum_input::equihash_submit(stratum_input::member(request.value, "params"), 192, 7, 28));
    }
    Json subscription("[\"miniZ/2.5e3\",null,\"pool.example\",2192]");
    assert(stratum_input::subscribe(subscription.value));
    Json bad_subscription("[12]"); assert(!stratum_input::subscribe(bad_subscription.value));
    assert(!stratum_input::string_params(nullptr));

    // Standard/ASICBoost and KawPow formats remain accepted without truncation.
    auto standard = std::vector<std::string>{quoted("worker"),quoted("1"),
        quoted("12345678"),quoted("01020304"),quoted("11223344")};
    Json standard_json(array(standard));
    assert(stratum_input::standard_submit(standard_json.value, "sha256"));
    standard.push_back(quoted("00002000"));
    Json asicboost(array(standard));
    assert(stratum_input::standard_submit(asicboost.value, "sha256"));
    standard[5] = quoted(std::string(32,'f'));
    Json oversized_version(array(standard));
    assert(!stratum_input::standard_submit(oversized_version.value, "sha256"));
    for (bool prefix : {false, true}) {
        Json kawpow(array({quoted("worker"),quoted("1"),
            quoted(std::string(prefix ? "0x" : "") + std::string(16,'a')),
            quoted(std::string(64,'b')),quoted("0x" + std::string(64,'c'))}));
        assert(stratum_input::kawpow_submit(kawpow.value));
    }
    Json short_kawpow("[\"worker\",\"1\",\"0x\",\"\",\"\"]");
    assert(!stratum_input::kawpow_submit(short_kawpow.value));
    std::cout << "Stratum input regressions passed (no network or mining).\n";
}
