#include <cassert>
#include <fstream>
#include <iostream>
#include <string>
#include <vector>

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
        "rejected solution/nonce/personalization mutations. No mining or network.\n";
}
